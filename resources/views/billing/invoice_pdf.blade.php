<!DOCTYPE html>
<html>

<head>
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body {
            font-family: sans-serif;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .details {
            width: 100%;
            margin-bottom: 20px;
        }

        .details td {
            vertical-align: top;
        }

        .items {
            width: 100%;
            border-collapse: collapse;
        }

        .items th,
        .items td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        .total {
            text-align: right;
            margin-top: 20px;
        }

        .paid {
            color: green;
            font-weight: bold;
            border: 2px solid green;
            padding: 5px;
            display: inline-block;
            transform: rotate(-15deg);
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>INVOICE</h1>
        <p>{{ $invoice->tenant->name ?? 'ISP Provider' }}</p>
    </div>

    <table class="details">
        <tr>
            <td>
                <strong>Bill To:</strong><br>
                {{ $invoice->customer->name }} {{ $invoice->customer->last_name ?? '' }}<br>
                {{ $invoice->customer->customerProfile->address ?? '' }}<br>
                {{ $invoice->customer->email }}
            </td>
            <td style="text-align: right;">
                <strong>Invoice #:</strong> {{ $invoice->number }}<br>
                <strong>Date:</strong> {{ $invoice->issue_date->format('Y-m-d') }}<br>
                <strong>Due Date:</strong> {{ $invoice->due_date->format('Y-m-d') }}<br>
                <strong>Status:</strong> {{ strtoupper($invoice->status) }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        <p><strong>Subtotal:</strong> {{ number_format($invoice->subtotal, 2) }}</p>
        <p><strong>Total:</strong> {{ number_format($invoice->total, 2) }}</p>
        <p><strong>Balance Due:</strong> {{ number_format($invoice->balance_due, 2) }}</p>
    </div>

    @if($invoice->status == 'paid')
        <div style="text-align: center; margin-top: 50px;">
            <span class="paid">PAID</span>
        </div>
    @endif
</body>

</html>