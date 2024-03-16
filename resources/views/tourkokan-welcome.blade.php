<!DOCTYPE html>
<html>
<head>
    <title>Welcome Email</title>
</head>
<body>
    <h1>Explore the beauty of kokan</h1>
    <p>Hello {{ $user->name }},</p>
    <p>Welcome to Tourkokan family. We're excited to have you as a new member!</p>
    <p>This is you uniq user id: {{ $user->uid }}, You can use this to change your wrong email.</p>
</body>
</html>
