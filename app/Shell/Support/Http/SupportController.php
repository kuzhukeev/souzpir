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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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

    public function store(#[SensitiveParameter] Request $request, SupportActions $actions, SupportQueries $query, TicketDelivery $delivery): mixed
    {
        // Framework frames retain this Request object. Move the payload to a local variable,
        // then remove form, JSON, raw body, query input and uploaded file references from it.
        $input = $request->all();
        $server = $request->server->all();
        $server['QUERY_STRING'] = '';
        $server['REQUEST_URI'] = explode('?', $server['REQUEST_URI'] ?? '/help/support', 2)[0];
        $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '');
        $request->setJson(new InputBag);
        // Laravel caches converted uploads separately from the Symfony file bag.
        (new ReflectionProperty(Request::class, 'convertedFiles'))->setValue($request, null);

        $stage = 'authorize';
        try {
            Gate::authorize('create', Ticket::class);
            $stage = 'validation';
            if (isset($input['attachments']) && is_array($input['attachments']) && ! array_is_list($input['attachments'])) {
                throw ValidationException::withMessages(['attachments' => __('Shell/Support/messages.validation.array', ['attribute' => __('Shell/Support/messages.attributes.attachments')])]);
            }
            $data = Validator::make($input, [
                'subject' => ['required', Rule::in(array_keys(__('Shell/Support/messages.topics')))],
                'module' => ['required', Rule::in(array_keys(__('Shell/Support/messages.modules')))],
                'body' => ['required', 'string', 'min:10', 'max:10000'],
                'support_access' => ['required', 'boolean'],
                'attachments' => ['sometimes', 'array', 'max:'.(int) config('features.E1-12.defaults.max_files')],
                'attachments.*' => ['required', 'extensions:jpg,jpeg,png,webp,pdf,txt', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'])->max((int) config('features.E1-12.defaults.max_file_kb'))],
            ], __('Shell/Support/messages.validation'), __('Shell/Support/messages.attributes'))->validate();
            $stage = 'create';
            $ticket = $actions->create($request, $data);
            $stage = 'delivery';
            $result = $delivery->send($ticket);

            return response()->json(['data' => ['ticket' => $query->ticket($ticket->fresh()), 'delivery' => $result]], 201);
        } catch (Throwable $error) {
            // Reconstruct known responses, without their original traces, previous exception,
            // SQL bindings, or Request. Validation messages come from this module's fixed rules.
            if ($error instanceof ValidationException && in_array($stage, ['validation', 'create'], true)) {
                $errors = $stage === 'create' ? ['attachments' => __('Shell/Support/messages.upload_failed')] : $error->errors();
                $safe = ValidationException::withMessages($errors);
                $safe->status = $error->status;
                $safe->errorBag = $error->errorBag;
                $safe->redirectTo = $error->redirectTo;
                throw $safe;
            }
            if ($error instanceof AuthenticationException) {
                throw new AuthenticationException;
            }
            if ($error instanceof AuthorizationException) {
                throw (new AuthorizationException)->withStatus($error->status());
            }
            if ($error instanceof ModelNotFoundException) {
                throw new ModelNotFoundException;
            }
            if ($error instanceof HttpExceptionInterface) {
                throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
            }
            throw new RuntimeException('Support request failed.');
        }
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
