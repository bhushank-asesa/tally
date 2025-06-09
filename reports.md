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

## Expenses, incomes and others

```sql
SELECT CASE WHEN
    l.top_parent IN(
        'Sales Accounts',
        'Indirect Incomes',
        'Direct Incomes'
    ) THEN 'Income' WHEN l.top_parent IN(
        'Indirect Expenses',
        'Direct Expenses',
        'Purchase Accounts'
    ) THEN 'Expense' WHEN l.top_parent IN(
        'Bank Accounts',
        'Cash-in-Hand',
        'Sundry Debtors'
    ) THEN 'Asset' WHEN l.top_parent IN(
        'Loans (Liability)',
        'Sundry Creditors'
    ) THEN 'Liability' ELSE 'Other'
END AS nature,
SUM(le.amount) AS total_amount
FROM
    vouchers v
JOIN voucher_ledger_entries le ON
    v.id = le.voucher_id
LEFT JOIN ledgers l ON
    le.ledger_name = l.name
GROUP BY
    nature
ORDER BY
    total_amount
DESC;
```

```sql
SELECT type,sum(amount) FROM `voucher_ledger_entries` group by type;
```
