<!DOCTYPE html>
<html lang="en">

<head>
    <!-- Basic SEO -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tourkokan | Sun Sand & Serenity</title>
    <meta name="description" content="Explore the beauty of the Konkan region with Tourkokan. Dive into adventures, cultural experiences, and scenic wonders. Your gateway to unforgettable memories!">
    <meta name="keywords" content="Tourkokan, Konkan, Sindhudurg, tourism, travel, MSRTC timetable, ACT timetable, AST timetable, adventures, scenic spots, beaches, forts, temples, historical sites, cultural experiences, travel guide, explore Konkan, adventure tourism, Konkan attractions, local cuisine">
    <meta name="author" content="Tourkokan">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta http-equiv="Content-Language" content="en">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="Tourkokan | Home">
    <meta property="og:description" content="Explore the beauty of the Konkan region with Tourkokan. Dive into adventures, cultural experiences, and scenic wonders. Your gateway to unforgettable memories!">
    <meta property="og:image" content="{{ asset('logo.png') }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ url()->current() }}">
    <meta property="twitter:title" content="Tourkokan | Home">
    <meta property="twitter:description" content="Explore the beauty of the Konkan region with Tourkokan. Dive into adventures, cultural experiences, and scenic wonders. Your gateway to unforgettable memories!">
    <meta property="twitter:image" content="{{ asset('logo.png') }}">

    <!-- Favicon -->
    <link rel="icon" sizes="200x200" href="{{ asset('logo.png') }}" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="60x60" href="{{ asset('logo.png') }}">
    <link rel="apple-touch-icon" sizes="76x76" href="{{ asset('logo.png') }}">
    <link rel="apple-touch-icon" sizes="120x120" href="{{ asset('logo.png') }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ asset('logo.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('logo.png') }}">

    <!-- Structured Data -->
    <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "Organization",
            "name": "Tourkokan",
            "url": "{{ url()->current() }}",
            "logo": "{{ asset('logo.png') }}",
            "sameAs": [
                "https://www.facebook.com/people/Tourkokan/61560289596939/?mibextid=LQQJ4d",
                "https://www.instagram.com/tour_kokan/"
            ],
            "address": {
                "@@type": "PostalAddress",
                "addressLocality": "Sindhudurg",
                "addressRegion": "Maharashtra",
                "addressCountry": "India"
            },
            "description": "Explore the beauty of the Konkan region with Tourkokan. Dive into adventures, cultural experiences, and scenic wonders. Your gateway to unforgettable memories!"
        }
    </script>

    <!-- Styles -->
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<style>
    /* Loading screen styling */
    #loading-screen {
        display: flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        width: 100%;
        height: 100%;
        background-color: #ffffff;
        z-index: 9999;
    }

    #loading-screen img {
        width: 40vw; /* 40% of the viewport width */
        max-width: 300px; /* Limit size on small screens */
        max-height: 40vh; /* Limit height to 40% of viewport height */
    }

    /* Adjustments for mobile */
    @@media (max-width: 600px) {
        #loading-screen img {
            width: 50vw;
            height: auto;
        }
    }

    /* WhatsApp Button styling */
    #whatsapp-button {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1000;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background-color: #25D366;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        transition: transform 0.2s;
    }

    #whatsapp-button img {
        width: 40px;
        height: 40px;
    }

    #whatsapp-button:hover {
        transform: scale(1.1);
    }
</style>

<body>
    <!-- Loading Screen with Logo -->
    <div id="loading-screen">
        <img src="{{ asset('logo.png') }}" alt="Tourkokan Logo">
    </div>

    <!-- Main App Root -->
    <div id="app"></div>

    <!-- WhatsApp Floating Button -->
    <a href="https://wa.me/8454025747" target="_blank" id="whatsapp-button">
        <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WhatsApp" />
    </a>

    <!-- Scripts -->
    <script src="{{ mix('js/app.js') }}"></script>
    <script>
        // Hide the loading screen once React fully loads
        document.addEventListener("DOMContentLoaded", function() {
            const loadingScreen = document.getElementById("loading-screen");
            loadingScreen.style.display = "none";
        });
    </script>
</body>

</html>
