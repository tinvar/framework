<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * What an AVIF's container says it holds, read in PHP before a decoder
 * sees a byte of it (ADR 0142).
 *
 * `getimagesize()` reads one number pair, the primary item's `ispe`, and a
 * decoder does not keep to it: a `grid` item whose `ispe` says 64 by 64
 * names tiles that make 16384 by 16384, and a coded item's own AV1
 * sequence header may declare a frame larger than its `ispe`. Both decode
 * at their real size, outside PHP's memory limit, from a file of 2 KB. So
 * the ISO media boxes are walked here -- `ftyp`, `meta` and its `hdlr`,
 * `pitm`, `iinf`, `iloc`, `iref`, `iprp` (`ipco`, `ipma`) and `idat` --
 * every size and count bounded, and anything not understood is refused:
 *
 * - an item of any type but a coded AV1 image (`av01`), `Exif` or `mime`:
 *   so no `grid`, `iovl`, `iden`, `tmap`, and no HEVC (`hvc1`) either;
 * - a derived-image reference (`dimg`), or a reference of a kind not known;
 * - a coded image without exactly one `ispe` and one `av1C`, or whose data
 *   is not found whole in the file;
 * - a protected item, a data reference outside the file, or an item
 *   located by another item.
 *
 * - a coded image any AV1 sequence header of which -- in its `av1C`, or in
 *   its data -- declares a frame larger than its `ispe`.
 *
 * Every coded image -- the primary, its alpha, a thumbnail -- is reported
 * with its `ispe`, for the caller to hold to its limits. A grid is refused
 * outright rather than checked tile by tile: an encoder writes one when
 * asked to, or for an image larger than the AVIF profiles' levels allow in
 * one frame (35.6 megapixels), past the limits anyway.
 */
final class AvifContainer
{
    /** The most boxes walked in one file. */
    private const int MAX_BOXES = 1024;

    /** The most items one file may hold. */
    private const int MAX_ITEMS = 64;

    /** The most properties in its `ipco`. */
    private const int MAX_PROPERTIES = 256;

    /** The most extents one item's data may be in. */
    private const int MAX_EXTENTS = 64;

    private const array ITEM_TYPES = ['av01', 'Exif', 'mime'];

    /** References that do not derive one image from others. */
    private const array REFERENCES = ['auxl', 'cdsc', 'thmb', 'prem'];

    private const array META_CHILDREN = ['hdlr', 'pitm', 'iloc', 'iinf', 'iref', 'iprp', 'idat', 'dinf', 'grpl'];

    private int $boxes = 0;

    /** @var array<int, string> item ID => item type */
    private array $items = [];

    /** @var list<array{string, int, int}> type, payload start, end */
    private array $properties = [];

    /** @var array<int, list<int>> item ID => property indexes, 1-based */
    private array $associations = [];

    /** @var array<int, array{int, list<array{int, int}>}> item ID => construction method, extents */
    private array $locations = [];

    /** @var list<array{string, int, list<int>}> type, from, to */
    private array $references = [];

    private ?int $primary = null;

    /** @var array{int, int}|null */
    private ?array $idat = null;

    private function __construct(private readonly string $bytes) {}

    /**
     * The primary image's size, and every coded image in the file.
     *
     * @return array{width: int, height: int, images: list<array{id: int, width: int, height: int, frameWidth: int, frameHeight: int}>}
     * @throws \UnexpectedValueException for a file not read as a plain still AVIF
     */
    public static function read(string $bytes): array
    {
        return new self($bytes)->parse();
    }

    /** @return array{width: int, height: int, images: list<array{id: int, width: int, height: int, frameWidth: int, frameHeight: int}>} */
    private function parse(): array
    {
        $top = $this->boxes(0, \strlen($this->bytes), true);
        if ($top === [] || $top[0][0] !== 'ftyp') {
            throw self::refused('it does not begin with its ftyp box');
        }
        $meta = null;
        foreach ($top as $i => [$type, $start, $end]) {
            if ($type === 'ftyp' && $i !== 0) {
                throw self::refused('it has a second ftyp box');
            }
            if ($type === 'meta') {
                if ($meta !== null) {
                    throw self::refused('it has a second meta box');
                }
                $meta = [$start, $end];
            } elseif (!\in_array($type, ['ftyp', 'mdat', 'free', 'skip'], true)) {
                throw self::refused("it holds a top-level '{$type}' box");
            }
        }
        if ($meta === null) {
            throw self::refused('it has no meta box');
        }
        $this->meta(...$meta);

        if ($this->primary === null || !isset($this->items[$this->primary])) {
            throw self::refused('its primary item is not among its items');
        }
        if ($this->items[$this->primary] !== 'av01') {
            throw self::refused("its primary item is a '{$this->items[$this->primary]}', not a coded AV1 image");
        }
        foreach ([...array_keys($this->associations), ...array_keys($this->locations)] as $id) {
            if (!isset($this->items[$id])) {
                throw self::refused("it describes item {$id}, which it does not hold");
            }
        }
        foreach ($this->references as [$type, $from, $to]) {
            foreach ([$from, ...$to] as $id) {
                if (!isset($this->items[$id])) {
                    throw self::refused("its '{$type}' reference names item {$id}, which it does not hold");
                }
            }
        }

        $images = [];
        $primary = null;
        foreach ($this->items as $id => $type) {
            if ($type !== 'av01') {
                continue;
            }
            $image = $this->image($id);
            $images[] = $image;
            if ($id === $this->primary) {
                $primary = $image;
            }
        }
        \assert($primary !== null);

        return ['width' => $primary['width'], 'height' => $primary['height'], 'images' => $images];
    }

    private function meta(int $start, int $end): void
    {
        $start = $this->fullBox($start, $end, [0]);
        $seen = [];
        foreach ($this->boxes($start, $end) as [$type, $from, $to]) {
            if (!\in_array($type, self::META_CHILDREN, true)) {
                throw self::refused("its meta box holds a '{$type}' box");
            }
            if (isset($seen[$type])) {
                throw self::refused("its meta box has a second '{$type}' box");
            }
            $seen[$type] = true;
            match ($type) {
                'hdlr' => $this->hdlr($from, $to),
                'pitm' => $this->pitm($from, $to),
                'iloc' => $this->iloc($from, $to),
                'iinf' => $this->iinf($from, $to),
                'iref' => $this->iref($from, $to),
                'iprp' => $this->iprp($from, $to),
                'idat' => $this->idat = [$from, $to],
                default => null,
            };
        }
        foreach (['hdlr', 'pitm', 'iloc', 'iinf', 'iprp'] as $required) {
            if (!isset($seen[$required])) {
                throw self::refused("its meta box has no '{$required}' box");
            }
        }
    }

    private function hdlr(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0]);
        if ($this->take($at + 4, 4, $end) !== 'pict') {
            throw self::refused('its handler is not for a picture');
        }
    }

    private function pitm(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0, 1], $version);
        $this->primary = $this->uint($at, $version === 0 ? 2 : 4, $end);
    }

    private function iinf(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0, 1], $version);
        $width = $version === 0 ? 2 : 4;
        $count = $this->uint($at, $width, $end);
        if ($count > self::MAX_ITEMS) {
            throw self::refused("it holds {$count} items, more than " . self::MAX_ITEMS);
        }
        $entries = $this->boxes($at + $width, $end);
        if (\count($entries) !== $count) {
            throw self::refused('its item list does not hold as many items as it says');
        }
        foreach ($entries as [$type, $from, $to]) {
            if ($type !== 'infe') {
                throw self::refused("its item list holds a '{$type}' box");
            }
            $p = $this->fullBox($from, $to, [2, 3], $infeVersion);
            $idWidth = $infeVersion === 2 ? 2 : 4;
            $id = $this->uint($p, $idWidth, $to);
            $protection = $this->uint($p + $idWidth, 2, $to);
            $itemType = $this->take($p + $idWidth + 2, 4, $to);
            if (isset($this->items[$id])) {
                throw self::refused("it holds item {$id} twice");
            }
            if ($protection !== 0) {
                throw self::refused("its item {$id} is protected");
            }
            if (!\in_array($itemType, self::ITEM_TYPES, true)) {
                throw self::refused("its item {$id} is a '" . self::printable($itemType) . "', which is not drawn here");
            }
            $this->items[$id] = $itemType;
        }
    }

    private function iref(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0, 1], $version);
        $width = $version === 0 ? 2 : 4;
        foreach ($this->boxes($at, $end) as [$type, $from, $to]) {
            if ($type === 'dimg') {
                throw self::refused('it holds an image derived from others');
            }
            if (!\in_array($type, self::REFERENCES, true)) {
                throw self::refused("it holds a '" . self::printable($type) . "' reference");
            }
            $source = $this->uint($from, $width, $to);
            $count = $this->uint($from + $width, 2, $to);
            if ($count < 1 || $count > self::MAX_ITEMS || $from + $width + 2 + $count * $width !== $to) {
                throw self::refused("its '{$type}' reference is not the size it says");
            }
            $targets = [];
            for ($i = 0; $i < $count; $i++) {
                $targets[] = $this->uint($from + $width + 2 + $i * $width, $width, $to);
            }
            $this->references[] = [$type, $source, $targets];
            if (\count($this->references) > self::MAX_ITEMS) {
                throw self::refused('it holds more references than items');
            }
        }
    }

    private function iprp(int $start, int $end): void
    {
        $children = $this->boxes($start, $end);
        if ($children === [] || $children[0][0] !== 'ipco') {
            throw self::refused('its item properties do not begin with their ipco box');
        }
        foreach ($children as $i => [$type, $from, $to]) {
            if ($type === 'ipco' && $i === 0) {
                foreach ($this->boxes($from, $to) as $property) {
                    $this->properties[] = $property;
                }
                if (\count($this->properties) > self::MAX_PROPERTIES) {
                    throw self::refused('it holds more than ' . self::MAX_PROPERTIES . ' properties');
                }
            } elseif ($type === 'ipma') {
                $this->ipma($from, $to);
            } else {
                throw self::refused("its item properties hold a '" . self::printable($type) . "' box");
            }
        }
    }

    private function ipma(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0, 1], $version, $flags);
        $count = $this->uint($at, 4, $end);
        if ($count > self::MAX_ITEMS) {
            throw self::refused('its property associations name more items than it may hold');
        }
        $at += 4;
        $idWidth = $version === 0 ? 2 : 4;
        $indexWidth = ($flags & 1) === 1 ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            $id = $this->uint($at, $idWidth, $end);
            $associations = $this->uint($at + $idWidth, 1, $end);
            $at += $idWidth + 1;
            if (isset($this->associations[$id])) {
                throw self::refused("its item {$id}'s properties are associated twice");
            }
            $indexes = [];
            for ($j = 0; $j < $associations; $j++) {
                $value = $this->uint($at, $indexWidth, $end);
                $at += $indexWidth;
                $index = $value & ($indexWidth === 2 ? 0x7FFF : 0x7F);
                if ($index === 0) {
                    continue;
                }
                if ($index > \count($this->properties)) {
                    throw self::refused("its item {$id} is given a property it does not hold");
                }
                $indexes[] = $index;
            }
            $this->associations[$id] = $indexes;
        }
        if ($at !== $end) {
            throw self::refused('its property associations are not the size they say');
        }
    }

    private function iloc(int $start, int $end): void
    {
        $at = $this->fullBox($start, $end, [0, 1, 2], $version);
        $sizes = $this->uint($at, 2, $end);
        $offsetSize = $sizes >> 12;
        $lengthSize = ($sizes >> 8) & 0xF;
        $baseSize = ($sizes >> 4) & 0xF;
        $indexSize = $version === 0 ? 0 : $sizes & 0xF;
        foreach ([$offsetSize, $lengthSize, $baseSize, $indexSize] as $size) {
            if (!\in_array($size, [0, 4, 8], true)) {
                throw self::refused('its item locations use a field size not in the standard');
            }
        }
        $at += 2;
        $idWidth = $version < 2 ? 2 : 4;
        $count = $this->uint($at, $idWidth, $end);
        $at += $idWidth;
        if ($count > self::MAX_ITEMS) {
            throw self::refused('it locates more items than it may hold');
        }
        for ($i = 0; $i < $count; $i++) {
            $id = $this->uint($at, $idWidth, $end);
            $at += $idWidth;
            $method = 0;
            if ($version > 0) {
                $method = $this->uint($at, 2, $end) & 0xF;
                $at += 2;
            }
            $reference = $this->uint($at, 2, $end);
            $base = $this->uint($at + 2, $baseSize, $end);
            $extents = $this->uint($at + 2 + $baseSize, 2, $end);
            $at += 4 + $baseSize;
            if (isset($this->locations[$id])) {
                throw self::refused("it locates item {$id} twice");
            }
            if ($reference !== 0) {
                throw self::refused("its item {$id}'s data is outside the file");
            }
            if ($method !== 0 && $method !== 1) {
                throw self::refused("its item {$id} is located by another item");
            }
            if ($extents < 1 || $extents > self::MAX_EXTENTS) {
                throw self::refused("its item {$id}'s data is in {$extents} extents");
            }
            $list = [];
            for ($j = 0; $j < $extents; $j++) {
                $at += $indexSize;
                $offset = $this->uint($at, $offsetSize, $end);
                $length = $this->uint($at + $offsetSize, $lengthSize, $end);
                $at += $offsetSize + $lengthSize;
                if ($length < 1) {
                    throw self::refused("its item {$id}'s data runs to the end of the file");
                }
                if ($offset > PHP_INT_MAX - $base) {
                    throw self::refused("its item {$id}'s data is not in the file");
                }
                $list[] = [$base + $offset, $length];
            }
            $this->locations[$id] = [$method, $list];
        }
        if ($at !== $end) {
            throw self::refused('its item locations are not the size they say');
        }
    }

    /** @return array{id: int, width: int, height: int, frameWidth: int, frameHeight: int} */
    private function image(int $id): array
    {
        $spatial = [];
        $config = [];
        foreach ($this->associations[$id] ?? [] as $index) {
            [$type, $from, $to] = $this->properties[$index - 1];
            if ($type === 'ispe') {
                $at = $this->fullBox($from, $to, [0]);
                $spatial[] = [$this->uint($at, 4, $to), $this->uint($at + 4, 4, $to)];
            } elseif ($type === 'av1C') {
                if ($to - $from < 4) {
                    throw self::refused("its item {$id}'s av1C is cut short");
                }
                $config[] = substr($this->bytes, $from + 4, $to - $from - 4);
            }
        }
        if (\count($spatial) !== 1 || \count($config) !== 1) {
            throw self::refused("its item {$id} has not exactly one ispe and one av1C");
        }
        [$width, $height] = $spatial[0];
        if ($width < 1 || $height < 1) {
            throw self::refused("its item {$id} has no size");
        }

        $frames = [...self::sequenceHeaders($config[0]), ...self::sequenceHeaders($this->data($id))];
        if ($frames === []) {
            throw self::refused("its item {$id} has no AV1 sequence header");
        }
        $frameWidth = max(array_column($frames, 0));
        $frameHeight = max(array_column($frames, 1));
        if ($frameWidth > $width || $frameHeight > $height) {
            throw self::refused("its item {$id} codes frames of up to {$frameWidth} by {$frameHeight}, larger than its ispe of {$width} by {$height}");
        }

        return ['id' => $id, 'width' => $width, 'height' => $height, 'frameWidth' => $frameWidth, 'frameHeight' => $frameHeight];
    }

    /** An item's data, its extents joined, each found whole in the file or its idat. */
    private function data(int $id): string
    {
        if (!isset($this->locations[$id])) {
            throw self::refused("its item {$id} is not located");
        }
        [$method, $extents] = $this->locations[$id];
        if ($method === 1 && $this->idat === null) {
            throw self::refused("its item {$id} is in an idat box it does not have");
        }
        [$from, $to] = $method === 1 ? $this->idat : [0, \strlen($this->bytes)];
        $data = '';
        foreach ($extents as [$offset, $length]) {
            if ($offset > $to - $from || $length > $to - $from - $offset) {
                throw self::refused("its item {$id}'s data is not in the file");
            }
            $data .= substr($this->bytes, $from + $offset, $length);
        }

        return $data;
    }

    /**
     * The largest frame each AV1 sequence header in $obus declares, as
     * max_frame_width and max_frame_height: no frame the stream codes is
     * larger.
     *
     * @return list<array{int, int}>
     */
    private static function sequenceHeaders(string $obus): array
    {
        $frames = [];
        $length = \strlen($obus);
        $at = 0;
        while ($at < $length) {
            $header = \ord($obus[$at]);
            if (($header & 0x80) !== 0) {
                throw self::refused('its AV1 data is not a stream of OBUs');
            }
            $type = ($header >> 3) & 0xF;
            $at += 1 + (($header >> 2) & 1);
            if (($header & 0x02) !== 0) {
                $size = 0;
                for ($i = 0; ; $i++) {
                    if ($i === 8 || $at >= $length) {
                        throw self::refused('its AV1 data is cut short');
                    }
                    $byte = \ord($obus[$at++]);
                    $size |= ($byte & 0x7F) << (7 * $i);
                    if (($byte & 0x80) === 0) {
                        break;
                    }
                }
            } else {
                $size = $length - $at;
            }
            if ($at > $length || $size < 0 || $size > $length - $at) {
                throw self::refused('its AV1 data is cut short');
            }
            if ($type === 1) {
                $frames[] = self::maxFrame(substr($obus, $at, $size));
            }
            $at += $size;
        }

        return $frames;
    }

    /** @return array{int, int} max_frame_width and max_frame_height of one sequence header OBU's payload */
    private static function maxFrame(string $payload): array
    {
        $at = 0;
        $read = static function (int $count) use ($payload, &$at): int {
            if ($at + $count > \strlen($payload) * 8) {
                throw self::refused('its AV1 sequence header is cut short');
            }
            // Past 32 bits only the last 32 are kept: the long fields
            // read here are skipped, not used.
            $value = 0;
            for ($i = 0; $i < $count; $i++, $at++) {
                $value = (($value << 1) & 0xFFFFFFFF) | ((\ord($payload[$at >> 3]) >> (7 - ($at & 7))) & 1);
            }

            return $value;
        };

        $read(3); // seq_profile
        $read(1); // still_picture
        if ($read(1) === 1) { // reduced_still_picture_header, then seq_level_idx
            $read(5);
        } else {
            $decoderModel = false;
            $bufferDelayLength = 0;
            if ($read(1) === 1) { // timing_info_present_flag
                $read(64); // num_units_in_display_tick, time_scale
                if ($read(1) === 1) { // equal_picture_interval: num_ticks_per_picture_minus_1, a uvlc()
                    $zeros = 0;
                    while ($read(1) === 0) {
                        if (++$zeros === 32) {
                            throw self::refused('its AV1 sequence header is not read');
                        }
                    }
                    $read($zeros);
                }
                if ($read(1) === 1) { // decoder_model_info_present_flag
                    $decoderModel = true;
                    $bufferDelayLength = $read(5) + 1;
                    $read(42); // num_units_in_decoding_tick, and two lengths
                }
            }
            $initialDisplayDelay = $read(1) === 1;
            $operatingPoints = $read(5) + 1;
            for ($i = 0; $i < $operatingPoints; $i++) {
                $read(12); // operating_point_idc
                if ($read(5) > 7) { // seq_level_idx, then seq_tier
                    $read(1);
                }
                if ($decoderModel && $read(1) === 1) { // its decoder and encoder buffer delays, and low_delay_mode_flag
                    $read(2 * $bufferDelayLength + 1);
                }
                if ($initialDisplayDelay && $read(1) === 1) {
                    $read(4);
                }
            }
        }
        $widthBits = $read(4) + 1;
        $heightBits = $read(4) + 1;

        return [$read($widthBits) + 1, $read($heightBits) + 1];
    }

    /**
     * The boxes between $start and $end: type, payload start, end. A size
     * of 0 -- to the end of the file -- only at the top level.
     *
     * @return list<array{string, int, int}>
     */
    private function boxes(int $start, int $end, bool $top = false): array
    {
        $boxes = [];
        $at = $start;
        while ($at < $end) {
            if (++$this->boxes > self::MAX_BOXES) {
                throw self::refused('it holds more than ' . self::MAX_BOXES . ' boxes');
            }
            $size = $this->uint($at, 4, $end);
            $type = $this->take($at + 4, 4, $end);
            $payload = $at + 8;
            if ($size === 1) {
                $size = $this->uint($at + 8, 8, $end);
                $payload += 8;
            } elseif ($size === 0 && $top) {
                $size = $end - $at;
            }
            if ($type === 'uuid') {
                $payload += 16;
            }
            if ($size < $payload - $at || $size > $end - $at) {
                throw self::refused("its '" . self::printable($type) . "' box is not the size it says");
            }
            $boxes[] = [$type, $payload, $at + $size];
            $at += $size;
        }

        return $boxes;
    }

    /**
     * Past a full box's version and flags, held to the versions known.
     *
     * @param list<int> $versions
     * @param-out int $version
     * @param-out int $flags
     */
    private function fullBox(int $start, int $end, array $versions, ?int &$version = null, ?int &$flags = null): int
    {
        $word = $this->uint($start, 4, $end);
        $version = $word >> 24;
        $flags = $word & 0xFFFFFF;
        if (!\in_array($version, $versions, true)) {
            throw self::refused("a box of version {$version}, which is not read here");
        }

        return $start + 4;
    }

    private function uint(int $at, int $width, int $end): int
    {
        if ($width === 0) {
            return 0;
        }
        $bytes = $this->take($at, $width, $end);
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            if ($i === 0 && $width === 8 && \ord($bytes[0]) > 0x7F) {
                throw self::refused('a size larger than any file');
            }
            $value = ($value << 8) | \ord($bytes[$i]);
        }

        return $value;
    }

    private function take(int $at, int $length, int $end): string
    {
        if ($at < 0 || $at > $end - $length) {
            throw self::refused('a box is cut short');
        }

        return substr($this->bytes, $at, $length);
    }

    private static function printable(string $type): string
    {
        return preg_match('/\A[\x20-\x7E]{4}\z/', $type) === 1 ? $type : bin2hex($type);
    }

    private static function refused(string $why): \UnexpectedValueException
    {
        return new \UnexpectedValueException("The AVIF is not read: {$why}.");
    }
}
