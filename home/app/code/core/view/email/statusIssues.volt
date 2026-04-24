<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>{{ title_1 }}</title>
    <style>
        body {
            background-color: #F2F2F2;
        }
        
        .card {
            text-transform: capitalize;
            background-color: #FFFFFF;
            color: #333333;
            font-family: Arial, sans-serif;
            font-size: 16px;
            line-height: 1.5;
            margin: 20px auto;
            padding: 0;
            max-width: 800px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .container {
            max-width: 100%;
            padding: 20px;
            background: linear-gradient(to right, #7B68EE, #8B008B);
        }

        h1 {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #FFFFFF;
        }

        p {
            color: #F2F2F2;
        }

        .timestamp {
            font-size: 14px;
            margin-top: 20px;
            color: #A9A9A9;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="container">
            <h1>{{ marketplace }} {{ service }}</h1>
            <p>{{ p_1 }} {{ marketplace }} {{ service }}. {{ reason }}.</p>
            <div class="timestamp">{{ div_1 }} {{ at }}</div>
        </div>
    </div>
</body>

</html>
