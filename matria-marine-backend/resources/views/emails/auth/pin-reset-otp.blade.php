<x-mail::message>
# Reset Your Security PIN

You are receiving this email because a request was made to reset the security PIN for your account.

Your One-Time Password (OTP) is:

<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire in 10 minutes.

If you did not request a PIN reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>