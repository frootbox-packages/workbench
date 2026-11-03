<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Workbench\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Files below public/ of a project, which a web server would deliver
 * itself: published assets (symlinks in development) and generated media.
 * Never PHP files or hidden files.
 */
final class StaticFile
{
    private const array TYPES = [
        'avif' => 'image/avif',
        'css' => 'text/css; charset=utf-8',
        'gif' => 'image/gif',
        'html' => 'text/html; charset=utf-8',
        'ico' => 'image/vnd.microsoft.icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'mjs' => 'text/javascript; charset=utf-8',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'ogg' => 'audio/ogg',
        'otf' => 'font/otf',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'vtt' => 'text/vtt; charset=utf-8',
        'webm' => 'video/webm',
        'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'xml' => 'application/xml',
    ];

    /**
     * The file as a response, or null if the request is not for one.
     */
    public static function respond(string $publicDirectory, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = rawurldecode($request->getUri()->getPath());
        $segments = explode('/', $path);

        if (str_contains($path, "\0") || \in_array('..', $segments, true) || array_any($segments, static fn(string $segment): bool => str_starts_with($segment, '.'))) {
            return null;
        }

        $file = $publicDirectory . $path;
        $extension = strtolower(pathinfo($file, \PATHINFO_EXTENSION));

        if ('' === $extension || \in_array($extension, ['php', 'phtml', 'phar'], true) || !is_file($file)) {
            return null;
        }

        $modified = (int) filemtime($file);
        $etag = '"' . dechex($modified) . '-' . dechex((int) filesize($file)) . '"';
        $headers = [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache',
        ];

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(304, $headers);
        }

        $handle = fopen($file, 'r');

        return new Response(200, [...$headers, 'Content-Length' => (string) filesize($file)], 'HEAD' === $request->getMethod() || false === $handle ? '' : Stream::create($handle));
    }
}
