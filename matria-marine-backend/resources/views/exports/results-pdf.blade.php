<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #007bff;
            margin: 0;
            font-size: 28px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .info {
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #007bff;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #e8f4f8;
        }
        .rank {
            font-weight: bold;
            text-align: center;
        }
        .rank-1 { color: #ffd700; }
        .rank-2 { color: #c0c0c0; }
        .rank-3 { color: #cd7f32; }
        .score {
            text-align: center;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Pageantry Tabulation System</h1>
        <p>{{ $title }}</p>
    </div>

    <div class="info">
        <p><strong>Filter:</strong> {{ ucfirst(str_replace('_', ' ', $filter)) }}</p>
        <p><strong>Generated:</strong> {{ $generated_at }}</p>
        <p><strong>Total Candidates:</strong> {{ count($results) }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Candidate #</th>
                <th>Name</th>
                @if($filter === 'overall')
                    <th>Sports Attire (20%)</th>
                    <th>Swimsuit (20%)</th>
                    <th>Gown (30%)</th>
                    <th>Q&A (30%)</th>
                    <th>Total Score</th>
                @else
                    <th>Score</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($results as $index => $result)
                <tr>
                    <td class="rank rank-{{ $index + 1 <= 3 ? $index + 1 : 'other' }}">
                        #{{ $index + 1 }}
                    </td>
                    <td class="rank">{{ $result['candidate']->candidate_number }}</td>
                    <td>{{ $result['candidate']->name }}</td>
                    @if($filter === 'overall')
                        <td class="score">{{ number_format($result['sports_attire'], 2) }}</td>
                        <td class="score">{{ number_format($result['swimsuit'], 2) }}</td>
                        <td class="score">{{ number_format($result['gown'], 2) }}</td>
                        <td class="score">{{ number_format($result['qa'], 2) }}</td>
                        <td class="score">{{ number_format($result['total'], 2) }}</td>
                    @else
                        <td class="score">{{ number_format($result['score'], 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>Pageantry Tabulation System - Generated on {{ $generated_at }}</p>
        <p>This document contains confidential competition results.</p>
    </div>
</body>
</html>

