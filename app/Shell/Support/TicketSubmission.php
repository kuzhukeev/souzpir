<?php

namespace App\Shell\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SensitiveParameter;

/** Hashes the complete intended request without persisting its contents as a fingerprint. */
final class TicketSubmission
{
    public function fingerprint(#[SensitiveParameter] array $data): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Submission identity unavailable.');
        }
        $attachments = [];
        foreach ($data['attachments'] ?? [] as $file) {
            if (! $file instanceof UploadedFile || ! is_string($path = $file->getRealPath())) {
                throw new RuntimeException('Submission identity unavailable.');
            }
            $digest = @hash_file('sha256', $path);
            if (! is_string($digest)) {
                throw new RuntimeException('Submission identity unavailable.');
            }
            $attachments[] = [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sha256' => $digest,
            ];
        }
        $payload = [
            'subject' => $data['subject'],
            'module' => $data['module'],
            'body' => $data['body'],
            'support_access' => (bool) $data['support_access'],
            'attachments' => $attachments,
        ];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), hash('sha256', 'support-submission-v1:'.$key, true));
    }
}
