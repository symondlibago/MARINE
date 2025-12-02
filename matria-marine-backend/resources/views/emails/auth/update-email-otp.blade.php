<x-mail::message>
# Verify Your New Email

Please use the following One-Time Password (OTP) to confirm your email address change.

Your OTP is:
<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire in 10 minutes. If you did not request this change, please secure your account immediately.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>