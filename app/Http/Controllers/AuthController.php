<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\User;
use App\PasswordReset as PWRT;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['register', 'forgetPassword','reset', 'resetPassword', 'resetPasswordByToken']);
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
        $request->validate([
            'old_password' => 'required|hash:'.auth()->user()->password,
            'password' => 'required|different:old_password|confirmed',
        ], [
            'old_password.hash' => '旧密码输入错误！',
        ], [
            'old_password' => '旧密码',
        ]);

        auth()->user()->update([
            'password' => bcrypt($request->get('password')),
        ]);

        return response()->json();
    }

    public function resetPasswordByToken(Request $request)
    {
        $this->validate($request, [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:6',
        ]);

        $email = $request->input('email');
        if (Hash::check($request->input('token'), PWRT::where('email', $email)->value('token'))) {
            User::where('email', $email)->update([
                'password' => bcrypt($request->input('password')),
            ]);
            PWRT::where('email', $email)->delete();

            return response()->json([
                'status' => 200,
                'message' => '密码修改成功，请重新登录！'
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => '密码修改失败，请重新发送找回密码邮件！'
            ]);
        }
    }

    /**
     * Get the password reset credentials from the request.
     *
     * @param \Illuminate\Http\Request $request
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
