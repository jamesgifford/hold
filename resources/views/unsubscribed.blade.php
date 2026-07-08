<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribed</title>
    <style>
        :root {
            --hold-bg: #f5f6f8;
            --hold-card-bg: #ffffff;
            --hold-text: #1a1d24;
            --hold-accent: #2563eb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--hold-bg);
            color: var(--hold-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }

        .hold-card {
            width: 100%;
            max-width: 30rem;
            background: var(--hold-card-bg);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .hold-card h1 {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
        }

        .hold-card p {
            margin: 0;
            opacity: 0.75;
        }

        .hold-email { font-weight: 600; opacity: 1; }
    </style>
</head>
<body>
    <main class="hold-card">
        <h1>You've been unsubscribed</h1>
        <p>We won't email <span class="hold-email">{{ $email }}</span> again. Sorry to see you go.</p>
    </main>
</body>
</html>
