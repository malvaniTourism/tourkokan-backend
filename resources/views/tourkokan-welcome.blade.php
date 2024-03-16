<!DOCTYPE html>
<html>
<head>
    <title>Welcome Email</title>
</head>
<body>
    <h1>Explore the beauty of kokan</h1>
    <p>Hello {{ $user->name }},</p>
    <p>Welcome to Tourkokan family. We're excited to have you as a new member!</p>
    <p>This is your unique user id: {{ $user->uid }}, You can use this to change your wrong email.</p>
    <p>This is your teporary user password: {{ $password }}, This password is valid for 1 week.</p>
</body>
</html>
