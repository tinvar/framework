<?php

declare(strict_types=1);

/**
 * What an email may be (ADR 0086): links held to https, the message's own
 * origin or mailto:, images to that origin or one allowed, nothing that runs
 * or fetches -- checked on the HTML the renderer hands back, whatever drew
 * it -- and headers no value can add a line to.
 */

namespace Tinvar\Tests\Mail\SafeMail;

use Tinvar\Data\Data;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\MailRenderingException;
use Tinvar\Mail\Message;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\SafeMailHtml;

final readonly class NoteData extends Data
{
    public function __construct(public string $note) {}
}

/** Mail to one tenant's reader, on that tenant's origin. */
final readonly class Note implements Mailable
{
    public function __construct(public string $origin) {}

    public function email(): Email
    {
        return new Email(new Address('ada@example.test'), 'A note', 'note', new NoteData('hi'), origin: $this->origin);
    }
}

/** A renderer that answers whatever HTML it was given -- a template's own markup, or a compromised renderer's. */
final class Draws implements MailRenderer
{
    public ?LinkPolicy $links = null;

    public function __construct(private readonly string $html) {}

    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        $this->links = $links;
        preg_match_all('/href="([^"]*)"/', $this->html, $m);

        return new RenderedMail($this->html, 'Note ' . implode(' ', array_map(static fn (string $h): string => html_entity_decode($h), $m[1])));
    }
}

function send(string $html, string $origin = 'https://acme.test'): Message
{
    return new Mailer(new ArrayTransport(), new Draws($html), new Address('mail@acme.test'))->send(new Note($origin));
}

function safe(string $html, ?LinkPolicy $links = null): void
{
    SafeMailHtml::assert($html, $links ?? new LinkPolicy('https://acme.test'));
}

describe('a link policy', function () {
    it('allows https anywhere, its own origin, and mailto: one address', function () {
        $links = new LinkPolicy('http://127.0.0.1:8000');

        expect($links->allowsLink('https://elsewhere.example/x?a=1'))->toBeTrue()
            ->and($links->allowsLink('http://127.0.0.1:8000/orders/1'))->toBeTrue()
            ->and($links->allowsLink('http://127.0.0.1:8000'))->toBeTrue()
            ->and($links->allowsLink('mailto:help@acme.test?subject=Help'))->toBeTrue();
    });

    it('refuses every other link', function (string $href) {
        expect(new LinkPolicy('http://127.0.0.1:8000')->allowsLink($href))->toBeFalse();
    })->with([
        'javascript' => ['javascript:alert(1)'],
        'javascript, upper case' => ['JAVASCRIPT:alert(1)'],
        'javascript, a tab in it' => ["java\tscript:alert(1)"],
        'javascript, leading space' => [' javascript:alert(1)'],
        'data' => ['data:text/html,<script>alert(1)</script>'],
        'vbscript' => ['vbscript:msgbox'],
        'plain http elsewhere' => ['http://acme.test/x'],
        'another port' => ['http://127.0.0.1:9000/x'],
        'a longer port' => ['http://127.0.0.1:80001/x'],
        'the origin as a user' => ['http://127.0.0.1:8000@evil.example/'],
        'a user on https' => ['https://acme.test@evil.example/'],
        'relative' => ['/orders/1'],
        'scheme-relative' => ['//evil.example/'],
        'a fragment' => ['#top'],
        'mailto, two addresses' => ['mailto:a@acme.test,b@evil.example'],
        'mailto, a line break' => ["mailto:a@acme.test\r\nBcc: x@evil.example"],
        'a line separator' => ["https://acme.test/\u{2028}x"],
        'empty' => [''],
    ]);

    it('loads images only from its origin or one allowed, with no query or fragment', function () {
        $links = new LinkPolicy('https://acme.test', ['https://cdn.example']);

        expect($links->allowsImage('https://acme.test/brand/mark-64.png'))->toBeTrue()
            ->and($links->allowsImage('https://cdn.example/a.png'))->toBeTrue()
            ->and($links->allowsImage('https://tracker.example/open.gif'))->toBeFalse()
            ->and($links->allowsImage('https://acme.test/open.gif?u=ada@example.test'))->toBeFalse()
            ->and($links->allowsImage('https://acme.test/a.png#x'))->toBeFalse()
            ->and($links->allowsImage('https://acme.test'))->toBeFalse()
            ->and($links->allowsImage('https://acme.test.evil.example/a.png'))->toBeFalse()
            ->and($links->allowsImage('data:image/png;base64,AAAA'))->toBeFalse()
            ->and(new LinkPolicy()->allowsImage('https://acme.test/a.png'))->toBeFalse();
    });

    it('is built only from an origin: https, or http on this machine', function (string $origin) {
        expect(fn () => new LinkPolicy($origin))->toThrow(\InvalidArgumentException::class);
    })->with(['http://acme.test', 'https://acme.test/path', 'https://acme.test/', 'javascript:alert(1)', 'https://u@acme.test']);

    it('allows images only from https origins', function () {
        expect(fn () => new LinkPolicy('https://acme.test', ['http://cdn.example']))->toThrow(\InvalidArgumentException::class);
    });
});

describe('the HTML of an email', function () {
    it('passes what the kit draws', function () {
        safe('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="color-scheme" content="light"><title>x</title></head>'
            . '<body style="margin:0;background-color:#F7F7F5"><table role="presentation"><tr><td style="font-family:Inter, &#x27;Segoe UI&#x27;, sans-serif">'
            . '<img src="https://acme.test/brand/mark-64.png" width="22" height="22" alt="" fetchPriority="low">'
            . '<a href="https://acme.test/orders/1?a=1&amp;b=2">View</a> <a href="mailto:stop@acme.test">Stop</a>'
            . '</td></tr></table></body></html>');
        expect(true)->toBeTrue();
    });

    it('refuses what runs, fetches or takes input', function (string $html, string $message) {
        expect(fn () => safe($html))->toThrow(MailRenderingException::class, $message);
    })->with([
        'a script' => ['<p>x</p><script>alert(1)</script>', '<script>'],
        'a script, upper case' => ['<SCRIPT>alert(1)</SCRIPT>', '<script>'],
        'an svg' => ['<svg><script>alert(1)</script></svg>', '<svg>'],
        'an iframe' => ['<iframe src="https://acme.test/"></iframe>', '<iframe>'],
        'a stylesheet' => ['<link rel="stylesheet" href="https://acme.test/a.css">', '<link>'],
        'a preload' => ['<link rel="preload" as="image" href="https://acme.test/a.png">', '<link>'],
        'a base' => ['<base href="https://evil.example/">', '<base>'],
        'a form' => ['<form action="https://evil.example/"><input name="p"></form>', '<form>'],
        'a refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example/">', 'http-equiv'],
        'a handler' => ['<p onclick="alert(1)">x</p>', 'onclick'],
        'a handler on an image' => ['<img src="https://acme.test/a.png" onerror="alert(1)">', 'onerror'],
        'a javascript link' => ['<a href="javascript:alert(1)">x</a>', 'cannot link to javascript:alert(1)'],
        'an entity-encoded javascript link' => ['<a href="&#106;avascript:alert(1)">x</a>', 'cannot link to javascript'],
        'a data link' => ['<a href="data:text/html,x">x</a>', 'cannot link to data:'],
        'an http link elsewhere' => ['<a href="http://evil.example/">x</a>', 'cannot link to http://evil.example/'],
        'a tracking pixel' => ['<img src="https://tracker.example/o.gif" width="1" height="1">', 'cannot load the image'],
        'a per-reader image' => ['<img src="https://acme.test/o.gif?u=1">', 'cannot load the image'],
        'a srcset' => ['<img src="https://acme.test/a.png" srcset="https://tracker.example/a.png 2x">', 'srcset'],
        'a background' => ['<table><tr><td background="https://tracker.example/a.png">x</td></tr></table>', 'background'],
        'a style that fetches' => ['<p style="background:url(https://tracker.example/a.png)">x</p>', 'cannot fetch'],
        'a style that escapes' => ['<p style="background:u\72l(https://tracker.example/a.png)">x</p>', 'cannot fetch'],
        'a style element that imports' => ['<style>@import "https://tracker.example/a.css";</style>', 'cannot fetch'],
        'a video' => ['<video src="https://acme.test/a.mp4"></video>', '<video>'],
        'an href on a div' => ['<div href="https://acme.test/">x</div>', 'cannot have an href'],
    ]);

    // A comment to the parser here, markup to Outlook and old Internet
    // Explorer: whatever is inside was never checked.
    it('refuses conditional comments, and every other comment with them', function (string $html) {
        expect(fn () => safe($html))->toThrow(MailRenderingException::class, 'comment');
    })->with([
        'a conditional comment' => ['<p>x</p><!--[if mso]><a href="javascript:alert(1)">x</a><![endif]-->'],
        'one with a space' => ['<!-- [if gte mso 9]><img src="https://tracker.example/o.gif"><![endif]-->'],
        'downlevel-revealed' => ['<![if !mso]><img src="https://tracker.example/o.gif"><![endif]>'],
        'a plain comment' => ['<p>x</p><!-- a note -->'],
        'in a title' => ['<title><!--[if mso]>x<![endif]--></title>'],
    ]);
});

describe('headers', function () {
    it('refuse a subject or a name holding anything that would end a line', function (string $value) {
        expect(fn () => new Message(new Address('a@acme.test'), [new Address('b@acme.test')], "Hi{$value}Bcc: x@evil.example", '<p>x</p>', 'x'))
            ->toThrow(\InvalidArgumentException::class, 'subject on one line')
            ->and(fn () => new Address('a@acme.test', "Ada{$value}Bcc: x@evil.example"))->toThrow(\InvalidArgumentException::class, 'display name');
    })->with([
        'CR LF' => ["\r\n"],
        'LF' => ["\n"],
        'CR' => ["\r"],
        'NUL' => ["\0"],
        'vertical tab' => ["\v"],
        'next line' => ["\u{0085}"],
        'line separator' => ["\u{2028}"],
        'paragraph separator' => ["\u{2029}"],
    ]);

    it('refuse an address a recipient list could be split on', function (string $email) {
        expect(fn () => new Address($email))->toThrow(\InvalidArgumentException::class, 'not an email address');
    })->with([
        'a quoted comma' => ['"a,b"@acme.test'],
        'a quoted semicolon' => ['"a;b"@acme.test'],
        'a line break' => ["a@acme.test\r\nBcc: x@evil.example"],
        'an angle bracket' => ['a>@acme.test'],
    ]);

    it('refuse a subject longer than a subject is', function () {
        expect(fn () => new Message(new Address('a@acme.test'), [new Address('b@acme.test')], str_repeat('x', 256), '<p>x</p>', 'x'))
            ->toThrow(\InvalidArgumentException::class, 'at most 255');
    });
});
