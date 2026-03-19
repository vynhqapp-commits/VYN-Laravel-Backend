<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email', 'password' => 'required']);

            if (!$token = auth('api')->attempt($request->only('email', 'password'))) {
                return $this->unauthorized('Invalid credentials');
            }

            return $this->success($this->tokenPayload($token), 'Login successful');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users',
                'password' => 'required|min:6|confirmed',
            ]);

            $user = User::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'tenant_id' => null,
            ]);

            $user->assignRole(Role::findByName('customer', 'api'));

            $token = auth('api')->login($user);

            return $this->created($this->tokenPayload($token), 'Registration successful');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function registerCustomer(Request $request)
    {
        try {
            $request->validate([
                'email'     => 'required|email|unique:users',
                'password'  => 'required|min:6',
                'full_name' => 'nullable|string|max:255',
                'phone'     => 'nullable|string|max:30',
            ]);

            $user = User::create([
                'name'      => $request->full_name ?? $request->email,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'tenant_id' => null,
            ]);

            $user->assignRole(Role::findByName('customer', 'api'));
            $token = auth('api')->login($user);

            return $this->created($this->tokenPayload($token), 'Registration successful');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function registerSalonOwner(Request $request)
    {
        try {
            $request->validate([
                'salon_name' => 'required|string|max:255',
                'salon_address' => 'nullable|string|max:255',
                'email'      => 'required|email|unique:users',
                'password'   => 'required|min:6',
                'full_name'  => 'nullable|string|max:255',
                'phone'      => 'nullable|string|max:30',
            ]);

            $tenant = \App\Models\Tenant::create([
                'name'    => $request->salon_name,
                'address' => $request->salon_address,
                'phone'   => $request->phone,
            ]);

            $user = User::create([
                'name'      => $request->full_name ?? $request->email,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'tenant_id' => $tenant->id,
            ]);

            $user->assignRole(Role::findByName('salon_owner', 'api'));
            $token = auth('api')->login($user);

            return $this->created($this->tokenPayload($token), 'Salon account created successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function sendOtp(Request $request)
    {
        try {
            // Accept simple { email } from frontend or full { identifier, type, purpose }
            $email = $request->input('email') ?? $request->input('identifier');
            $request->merge([
                'identifier' => $email,
                'type'       => $request->input('type', 'email'),
                'purpose'    => $request->input('purpose', 'login'),
            ]);

            $request->validate([
                'identifier' => 'required|string',
                'type'       => 'required|in:phone,email',
                'purpose'    => 'required|in:login,register,reset_password',
            ]);

            OtpCode::where('identifier', $request->identifier)
                ->where('type', $request->type)
                ->where('purpose', $request->purpose)
                ->where('is_used', false)
                ->delete();

            $expiresInMinutes = 10;
            $otp = OtpCode::create([
                'identifier' => $request->identifier,
                'type'       => $request->type,
                'purpose'    => $request->purpose,
                'code'       => str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'expires_at' => now()->addMinutes($expiresInMinutes),
            ]);

            if ($request->type === 'email') {
                try {
                    Mail::to($request->identifier)->send(new OtpMail(
                        code: $otp->code,
                        purpose: $request->purpose,
                        expiresInMinutes: $expiresInMinutes,
                    ));
                } catch (\Throwable $e) {
                    Log::error('OTP email failed', [
                        'identifier' => $request->identifier,
                        'error'     => $e->getMessage(),
                    ]);
                    return $this->error('Failed to send verification email. Please try again.', 500);
                }
            } else {
                Log::info('OTP Code (non-email)', [
                    'identifier' => $request->identifier,
                    'purpose'    => $request->purpose,
                    'code'       => $otp->code,
                ]);
            }

            return $this->success(null, 'OTP sent successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function verifyOtp(Request $request)
    {
        try {
            // Accept simple { email, code } from frontend or full { identifier, type, purpose, code }
            $email = $request->input('email') ?? $request->input('identifier');
            $request->merge([
                'identifier' => $email,
                'type'       => $request->input('type', 'email'),
                'purpose'    => $request->input('purpose', 'login'),
            ]);

            $request->validate([
                'identifier' => 'required|string',
                'type'       => 'required|in:phone,email',
                'purpose'    => 'required|in:login,register,reset_password',
                'code'       => 'required|string|size:6',
            ]);

            $otp = OtpCode::where('identifier', $request->identifier)
                ->where('type', $request->type)
                ->where('purpose', $request->purpose)
                ->where('code', $request->code)
                ->where('is_used', false)
                ->first();

            if (!$otp || !$otp->isValid()) {
                return $this->error('Invalid or expired OTP', 422);
            }

            $otp->update(['is_used' => true]);

            $field = $request->type === 'email' ? 'email' : 'phone';
            $user  = User::where($field, $request->identifier)->first();

            if (!$user) {
                return $this->success(['verified' => true], 'OTP verified');
            }

            $token = auth('api')->login($user);

            return $this->success($this->tokenPayload($token), 'OTP verified and logged in');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function me()
    {
        try {
            $user = auth('api')->user()->load('roles');
            return $this->success(new UserResource($user));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function logout()
    {
        try {
            auth('api')->logout();
            return $this->success(null, 'Successfully logged out');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function tokenPayload(string $token): array
    {
        $user = auth('api')->user()->load('roles');
        return [
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => new UserResource($user),
        ];
    }
}
