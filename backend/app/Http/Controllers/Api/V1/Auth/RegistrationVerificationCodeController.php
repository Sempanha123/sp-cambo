<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\RegistrationEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationVerificationCodeController extends Controller
{
    public function __invoke(Request $request, RegistrationEmailVerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],
        ]);

        try {
            $result = $verification->send((string) $data['email']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'We could not send the verification email right now. Please try again shortly.',
                'code' => 'verification_email_unavailable',
            ], 503);
        }

        return response()->json([
            'data' => [
                'message' => 'Verification code sent. Check your email.',
                'expires_in' => $result['expires_in'],
                'resend_after' => $result['resend_after'],
            ],
        ]);
    }
}
