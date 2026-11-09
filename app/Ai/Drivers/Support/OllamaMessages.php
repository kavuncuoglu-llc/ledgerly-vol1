<?php

namespace App\Ai\Drivers\Support;

use App\Ai\Exceptions\InvalidRequest;
use stdClass;

final class OllamaMessages
{
    public static function translate(array $messages): array
    {
        $toolNames = [];

        $translated = [];

        foreach ($messages as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $toolNames[(string) ($call['id'] ?? '')] = (string) ($call['function']['name'] ?? '');
            }

            $translated[] = self::one($message, $toolNames);
        }

        return $translated;
    }

    private static function one(array $message, array $toolNames): array
    {
        $role = (string) ($message['role'] ?? 'user');

        if ($role === 'tool') {
            return self::toolResult($message, $toolNames);
        }

        $out = [
            'role' => $role,
            'content' => self::textOf($message['content'] ?? ''),
        ];

        $images = self::imagesOf($message['content'] ?? '');

        if ($images !== []) {
            $out['images'] = $images;
        }

        if (($message['tool_calls'] ?? []) !== []) {
            $out['tool_calls'] = array_map(self::toolCall(...), $message['tool_calls']);
        }

        return $out;
    }

    private static function toolResult(array $message, array $toolNames): array
    {
        $out = [
            'role' => 'tool',
            'content' => self::textOf($message['content'] ?? ''),
        ];

        $name = $toolNames[(string) ($message['tool_call_id'] ?? '')] ?? ($message['name'] ?? null);

        if (filled($name)) {
            $out['tool_name'] = (string) $name;
        }

        return $out;
    }

    private static function toolCall(array $call): array
    {
        $arguments = $call['function']['arguments'] ?? [];

        $decoded = is_string($arguments)
            ? json_decode($arguments, true)
            : $arguments;

        return [
            'function' => [
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($decoded) && $decoded !== []
                    ? $decoded
                    : new stdClass,
            ],
        ];
    }

    private static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $texts = [];

        foreach ($content as $part) {
            if (($part['type'] ?? 'text') === 'text') {
                $texts[] = (string) ($part['text'] ?? '');
            }
        }

        return implode("\n", $texts);
    }

    private static function imagesOf(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $images = [];

        foreach ($content as $part) {
            if (($part['type'] ?? '') === 'image_url') {
                $images[] = self::base64Of((string) ($part['image_url']['url'] ?? ''));
            }
        }

        return $images;
    }

    private static function base64Of(string $url): string
    {
        if (preg_match('#^data:[^;]+;base64,(?<data>.+)$#s', $url, $matches) !== 1) {
            throw new InvalidRequest('Ollama takes images as base64, not as URLs: send a data: URL.');
        }

        return $matches['data'];
    }
}
