<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlaygroundException;
use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Services\PlaygroundService;
use App\Support\AccessAllocationSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaygroundController extends Controller
{
    public function quota(Request $request, PlaygroundService $playground): JsonResponse
    {
        if (! AccessAllocationSchema::ready()) {
            return response()->json(AccessAllocationSchema::errorPayload(), 503);
        }

        try {
            return response()->json(['data' => $playground->quota($request->user())]);
        } catch (PlaygroundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        } catch (Throwable $exception) {
            $requestId = 'pgdiag_'.Str::lower(Str::random(12));
            Log::error('Playground quota load failed.', [
                'diagnostic_id' => $requestId,
                'user_id' => (int) $request->user()->id,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 1000),
            ]);

            return response()->json([
                'message' => "Playground data could not be loaded. Diagnostic reference: {$requestId}",
                'code' => 'playground_quota_load_failed',
                'request_id' => $requestId,
            ], 500);
        }
    }

    public function run(Request $request, PlaygroundService $playground): JsonResponse
    {
        if (! AccessAllocationSchema::ready()) {
            return response()->json(AccessAllocationSchema::errorPayload(), 503);
        }

        $input = $request->validate([
            'model' => ['required', 'string', 'max:150'],
            'protocol' => ['required', Rule::in(['messages', 'responses', 'chat_completions'])],
            'system_prompt' => ['nullable', 'string', 'max:100000'],
            'prompt' => ['nullable', 'string', 'max:500000', 'required_without:messages'],
            'messages' => ['nullable', 'array', 'min:1', 'max:40', 'required_without:prompt'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:500000'],
            'max_output_tokens' => ['required', 'integer', 'between:1,65536'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'funding_source' => ['nullable', Rule::in(['daily', 'balance'])],
        ]);

        $alias = ModelAlias::query()->published()->where('public_alias', $input['model'])->first();
        if (! $alias) {
            return response()->json(['message' => 'The selected model is not available.', 'code' => 'model_unavailable'], 404);
        }

        $capability = match ($input['protocol']) {
            'messages' => 'messages_api',
            'responses' => 'responses_api',
            'chat_completions' => 'chat_completions_api',
        };
        if (($alias->capabilities[$capability] ?? false) !== true) {
            return response()->json(['message' => 'The selected model does not support this protocol.', 'code' => 'model_unavailable'], 422);
        }

        try {
            return response()->json(['data' => $playground->run($request->user(), $alias, $input)]);
        } catch (PlaygroundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        } catch (Throwable $exception) {
            $requestId = 'pgrun_'.Str::lower(Str::random(12));
            Log::error('Playground inference run failed.', [
                'diagnostic_id' => $requestId,
                'user_id' => (int) $request->user()->id,
                'model_alias' => $alias->public_alias,
                'protocol' => (string) $input['protocol'],
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 1000),
            ]);

            return response()->json([
                'message' => "Playground request failed. Diagnostic reference: {$requestId}",
                'code' => 'playground_run_failed',
                'request_id' => $requestId,
            ], 500);
        }
    }
    public function stream(Request $request, PlaygroundService $playground): StreamedResponse|JsonResponse
    {
        if (! AccessAllocationSchema::ready()) {
            return response()->json(AccessAllocationSchema::errorPayload(), 503);
        }

        $input = $request->validate([
            'model' => ['required', 'string', 'max:150'],
            'protocol' => ['required', Rule::in(['messages', 'responses', 'chat_completions'])],
            'system_prompt' => ['nullable', 'string', 'max:100000'],
            'prompt' => ['nullable', 'string', 'max:500000', 'required_without:messages'],
            'messages' => ['nullable', 'array', 'min:1', 'max:40', 'required_without:prompt'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:500000'],
            'max_output_tokens' => ['required', 'integer', 'between:1,65536'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'funding_source' => ['nullable', Rule::in(['daily', 'balance'])],
        ]);

        $alias = ModelAlias::query()->published()->where('public_alias', $input['model'])->first();
        if (! $alias) {
            return response()->json(['message' => 'The selected model is not available.', 'code' => 'model_unavailable'], 404);
        }

        $capability = match ($input['protocol']) {
            'messages' => 'messages_api',
            'responses' => 'responses_api',
            'chat_completions' => 'chat_completions_api',
        };
        if (($alias->capabilities[$capability] ?? false) !== true) {
            return response()->json(['message' => 'The selected model does not support this protocol.', 'code' => 'model_unavailable'], 422);
        }

        try {
            return $playground->stream($request->user(), $alias, $input);
        } catch (PlaygroundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        } catch (Throwable $exception) {
            $requestId = 'pgstream_'.Str::lower(Str::random(12));
            Log::error('Playground streaming request failed before the stream started.', [
                'diagnostic_id' => $requestId,
                'user_id' => (int) $request->user()->id,
                'model_alias' => $alias->public_alias,
                'protocol' => (string) $input['protocol'],
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 1000),
            ]);

            return response()->json([
                'message' => "Playground stream could not be started. Diagnostic reference: {$requestId}",
                'code' => 'playground_run_failed',
                'request_id' => $requestId,
            ], 500);
        }
    }

}
