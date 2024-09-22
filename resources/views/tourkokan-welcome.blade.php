<!DOCTYPE html>
<html>

<head>
    <title>Welcome Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            background-color: #007bff;
            color: #ffffff;
            padding: 20px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .content {
            padding: 20px;
        }

        .content p {
            line-height: 1.6;
            color: #333333;
        }

        .footer {
            text-align: center;
            padding: 10px;
            background-color: #007bff;
            color: #ffffff;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
            font-size: 14px;
        }

        .highlight {
            color: #007bff;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Explore the Beauty of Kokan</h1>
        </div>
        <div class="content">
            <p>Hello <span class="highlight">{{ $user->name }}</span>,</p>
            <p>Welcome to the TourKokan family! We're excited to have you as a new member!</p>
            <p>Your unique user ID is: <span class="highlight">{{ $user->uid }}</span>. You can use this ID to update your email if necessary.</p>
            <p>Your temporary password is: <span class="highlight">{{ $password }}</span>. Please note that this password is valid for only one week. Make sure to change it as soon as possible for security reasons.</p>
            <p>We hope you enjoy exploring the beauty of Kokan with us!</p>
        </div>
        <div class="footer">
            &copy; 2024 TourKokan. All rights reserved.
        </div>
    </div>
</body>

</html>