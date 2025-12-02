<x-mail::message>
# Password Reset Request

You are receiving this email because we received a password reset request for your account.

Your password reset OTP is:
<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire in 10 minutes. If you did not request a password reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>