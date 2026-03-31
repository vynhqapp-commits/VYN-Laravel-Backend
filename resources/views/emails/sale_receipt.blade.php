<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h2 style="margin: 0 0 12px;">Receipt {{ $sale->invoice_number }}</h2>
    <p style="margin: 0 0 6px;">Date: {{ optional($sale->created_at)->format('Y-m-d H:i') }}</p>
    <p style="margin: 0 0 6px;">Total: {{ number_format((float) $sale->total, 2) }}</p>
    <p style="margin: 0 0 12px;">Status: {{ $sale->status }}</p>

    <table width="100%" cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; border-color: #e5e7eb;">
        <thead>
            <tr>
                <th align="left">Item</th>
                <th align="right">Qty</th>
                <th align="right">Price</th>
                <th align="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td align="right">{{ $item->quantity }}</td>
                    <td align="right">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td align="right">{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

