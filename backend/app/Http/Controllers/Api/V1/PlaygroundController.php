<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlaygroundException;
use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Services\PlaygroundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlaygroundController extends Controller
{
    public function quota(Request $request, PlaygroundService $playground): JsonResponse
    {
        try {
            return response()->json(['data' => $playground->quota($request->user())]);
        } catch (PlaygroundException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        }
    }

    public function run(Request $request, PlaygroundService $playground): JsonResponse
    {
        $input = $request->validate([
            'model' => ['required', 'string', 'max:150'],
            'protocol' => ['required', Rule::in(['messages', 'responses', 'chat_completions'])],
            'system_prompt' => ['nullable', 'string', 'max:12000'],
            'prompt' => ['required', 'string', 'max:50000'],
            'max_output_tokens' => ['required', 'integer', 'between:1,2048'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
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
        }
    }
}
