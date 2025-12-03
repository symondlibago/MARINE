<x-mail::message>
# Email Change Request

We received a request to change your account email address.

Use the OTP below to verify this change. This code is valid for 10 minutes.

<x-mail::panel>
# {{ $otp }}
</x-mail::panel>

If you did not request this change, please ignore this email. Your email address will remain unchanged.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>