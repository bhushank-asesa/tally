# Reports 

## Total Voucher Amount by Type

> Show total amounts per voucher type (Sales, Purchase, Payment, Receipt).

```sql
SELECT 
    voucher_type,
    SUM(CASE WHEN le.is_deemed_positive THEN -le.amount ELSE le.amount END) AS total_amount
FROM 
    vouchers v
JOIN 
    voucher_ledger_entries le ON v.id = le.voucher_id
GROUP BY 
    voucher_type
ORDER BY 
    total_amount DESC;
```

## Monthly Voucher Totals

> Show monthly totals for a specific voucher type (e.g., Sales).

```sql
SELECT 
    DATE_FORMAT(v.date, '%Y-%m') AS month,
    SUM(CASE WHEN le.is_deemed_positive THEN -le.amount ELSE le.amount END) AS total_amount
FROM 
    vouchers v
JOIN 
    voucher_ledger_entries le ON v.id = le.voucher_id
WHERE 
    v.voucher_type = 'Sales'
GROUP BY 
    month
ORDER BY 
    month;
```

### Top Ledgers by Amount

> Show which ledgers had the highest total amounts.

```sql
SELECT 
    le.ledger_name,
    SUM(CASE WHEN le.is_deemed_positive THEN -le.amount ELSE le.amount END) AS total_amount
FROM 
    voucher_ledger_entries le
GROUP BY 
    le.ledger_name
ORDER BY 
    total_amount DESC
LIMIT 10; 
```
