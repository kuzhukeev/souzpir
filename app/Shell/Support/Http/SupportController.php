<?php

namespace App\Shell\Support\Http;

use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Support\HelpQuery;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Shell\Support\StatusQuery;
use App\Shell\Support\SupportAccess;
use App\Shell\Support\SupportActions;
use App\Shell\Support\SupportQueries;
use App\Shell\Support\TicketDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class SupportController
{
    public function help(HelpQuery $query): mixed
    {
        return Inertia::render('Shell/Support/Help', ['help' => $query->read()]);
    }

    public function status(StatusQuery $query): mixed
    {
        return Inertia::render('Shell/Support/Status', ['status' => $query->read()]);
    }

    public function index(Request $request, SupportQueries $query): mixed
    {
        return Inertia::render('Shell/Support/Tickets', ['support' => $query->index($request)]);
    }

    public function store(Request $request, SupportActions $actions, SupportQueries $query, TicketDelivery $delivery): mixed
    {
        Gate::authorize('create', Ticket::class);
        $data = $request->validate([
            'subject' => ['required', Rule::in(array_keys(__('Shell/Support/messages.topics')))],
            'module' => ['required', Rule::in(array_keys(__('Shell/Support/messages.modules')))],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
            'support_access' => ['required', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('features.E1-12.defaults.max_files')],
            'attachments.*' => ['required', 'extensions:jpg,jpeg,png,webp,pdf,txt', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'])->max((int) config('features.E1-12.defaults.max_file_kb'))],
        ], __('Shell/Support/messages.validation'), __('Shell/Support/messages.attributes'));
        $ticket = $actions->create($request, $data);
        $result = $delivery->send($ticket);

        return response()->json(['data' => ['ticket' => $query->ticket($ticket->fresh()), 'delivery' => $result]], 201);
    }

    public function revoke(Request $request, string $ticket, SupportActions $actions, SupportQueries $query): mixed
    {
        return response()->json(['data' => ['ticket' => $query->ticket($actions->revoke($request, $ticket))]]);
    }

    public function download(Request $request, string $ticket, string $attachment, SupportAccess $access): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($ticket, $attachment, $access): mixed {
            $access->lock();
            $owned = $access->ticket($ticket);
            $file = TicketAttachment::query()->where('ticket_id', $owned->id)->where('user_id', $owned->user_id)->whereKey($attachment)->firstOrFail();
            $expected = 'support/'.$owned->organization_id.'/'.$owned->id.'/'.$file->id.'.bin';
            abort_unless(hash_equals($expected, $file->storage_path) && Storage::disk('local')->exists($expected), 404);
            $body = Storage::disk('local')->get($expected);
            abort_unless(is_string($body) && strlen($body) === $file->size_bytes && hash_equals($file->sha256, hash('sha256', $body)), 503, __('Shell/Support/messages.attachment_unavailable'));

            return response($body)->header('Content-Type', $file->mime_type)->header('Content-Length', (string) strlen($body))
                ->header('Content-Disposition', HeaderUtils::makeDisposition('attachment', $file->name, 'attachment'))
                ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "sandbox; default-src 'none'");
        });
    }
}
