<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email</title>
</head>
<body style="margin:0;background:#071426;color:#e8eef8;font-family:Arial,sans-serif;padding:32px 16px;">
    <div style="max-width:560px;margin:0 auto;background:#0d1c31;border:1px solid #223650;border-radius:16px;padding:28px;">
        <div style="font-size:20px;font-weight:700;margin-bottom:8px;">SP Cambo</div>
        <h1 style="font-size:24px;margin:0 0 12px;">Verify your email</h1>
        <p style="color:#aebbd0;line-height:1.6;margin:0 0 20px;">Enter this code on the registration page to finish creating your account.</p>
        <div style="font-size:34px;letter-spacing:8px;font-weight:800;text-align:center;background:#071426;border:1px solid #31527c;border-radius:12px;padding:20px;margin:20px 0;">{{ $code }}</div>
        <p style="color:#aebbd0;line-height:1.6;margin:0;">This code expires in {{ $expiresInMinutes }} minutes. If you did not request it, you can ignore this email.</p>
    </div>
</body>
</html>
