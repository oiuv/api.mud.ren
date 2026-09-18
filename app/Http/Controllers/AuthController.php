<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['register', 'forgetPassword', 'reset', 'resetPassword', 'resetPasswordByToken']);
    }

    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'username' => $request->get('username'),
            'email' => $request->get('email'),
            'password' => bcrypt($request->get('password')),
        ]);

        $user->sendActiveMail();

        $token = $user->createToken('Laravel Password Grant Client')->accessToken;

        return response()->json([
            'token' => $token,
        ]);
    }

    public function reset(Request $request)
    {
        return $request->has('email') ?
            $this->resetPasswordByToken($request) :
            $this->resetPassword($request);
    }

    public function resetPassword(Request $request)
    {
        abort_unless($request->user(), 401);

        $request->validate([
            'old_password' => 'required|string|hash:'.auth()->user()->password,
            'password' => 'required|string|different:old_password|confirmed|min:6',
        ], [
            'old_password.hash' => '旧密码输入错误！',
        ], [
            'old_password' => '旧密码',
        ]);

        auth()->user()->update([
            'password' => bcrypt($request->get('password')),
        ]);

        return response()->json([
            'status' => 200,
            'message' => '密码修改成功 ^_^',
        ]);
    }

    public function resetPasswordByToken(Request $request)
    {
        $this->validate($request, [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|confirmed|min:6',
        ]);

        $status = $this->broker()->reset($this->credentials($request), function (User $user, $password) {
            $user->password = Hash::make($password);
            $user->setRememberToken(Str::random(60));
            $user->save();

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => 200,
                'message' => '密码修改成功，请重新登录！',
            ]);
        }

        return response()->json([
            'status' => 422,
            'message' => '密码修改失败，请重新发送找回密码邮件！',
            'errors' => ['token' => ['密码修改失败，请重新发送找回密码邮件！']],
        ], 422);
    }

    /**
     * Get the password reset credentials from the request.
     *
     * @return array
     */
    protected function credentials(Request $request)
    {
        return $request->only(
            'email', 'password', 'password_confirmation', 'token'
        );
    }

    public function forgetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $this->broker()->sendResetLink(
            $request->only('email')
        );

        return response()->json();
    }

    public function broker()
    {
        return Password::broker();
    }
}
