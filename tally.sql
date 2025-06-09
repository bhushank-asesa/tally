CREATE TABLE
    vouchers (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_guid VARCHAR(100) COMMENT 'Voucher GUID from Tally',
        voucher_type VARCHAR(50) COMMENT 'Vouchertype name (e.g., Receipt, Sales)',
        voucher_number VARCHAR(50) COMMENT 'Voucher number',
        date DATE COMMENT 'Voucher date',
        party_ledger_name VARCHAR(150) COMMENT 'Main party ledger name',
        narration TEXT COMMENT 'Narration or notes',
        created_by VARCHAR(100) COMMENT 'created_by',
        is_optional BOOLEAN COMMENT 'Optional voucher flag',
        is_cancelled BOOLEAN COMMENT 'Cancelled flag',
        is_deleted BOOLEAN COMMENT 'Deleted flag',
        is_invoice BOOLEAN COMMENT 'Invoice flag',
        is_postdated BOOLEAN COMMENT 'Post-dated voucher flag',
        alter_id INT COMMENT 'Alter ID from Tally',
        master_id VARCHAR(50) COMMENT 'Master ID from Tally',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated'
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'Main voucher table (one row per voucher)';

CREATE TABLE
    voucher_ledger_entries (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_id BIGINT COMMENT 'Foreign key to vouchers.id',
        ledger_name VARCHAR(150) COMMENT 'Ledger name',
        type VARCHAR(150) COMMENT 'type',
        is_deemed_positive BOOLEAN COMMENT 'Credit = true, Debit = false',
        amount DECIMAL(15, 2) COMMENT 'Amount (positive)',
        is_party_ledger BOOLEAN COMMENT 'Marks party ledger in voucher',
        cost_centre VARCHAR(100) COMMENT 'Cost center if any',
        currency VARCHAR(20) COMMENT 'Currency name',
        exchange_rate DECIMAL(12, 6) COMMENT 'Exchange rate',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated',
        -- Foreign key constraint to link with vouchers table
        CONSTRAINT fk_voucher_ledger_entries_voucher_id FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'Ledger entries for each voucher (1+ rows per voucher)';

CREATE TABLE
    voucher_inventory_entries (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_id BIGINT COMMENT 'FK to vouchers.id',
        stock_item_name VARCHAR(150) COMMENT 'Stock item name',
        rate DECIMAL(15, 4) COMMENT 'Rate per unit',
        amount DECIMAL(15, 2) COMMENT 'Amount',
        actual_qty DECIMAL(15, 3) COMMENT 'Actual quantity',
        billed_qty DECIMAL(15, 3) COMMENT 'Billed quantity',
        discount DECIMAL(15, 2) COMMENT 'Discount amount',
        tax_classification VARCHAR(100) COMMENT 'GST or VAT classification',
        batch_allocation TEXT COMMENT 'Batch details JSON (optional)',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated',
        -- Foreign key constraint to link with vouchers table
        CONSTRAINT fk_voucher_inventory_entries_voucher_id FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'Inventory entries (items) per voucher';

CREATE TABLE
    voucher_bank_allocations (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_id BIGINT COMMENT 'FK to vouchers.id',
        instrument_number VARCHAR(50) COMMENT 'Cheque/Instrument number',
        instrument_date DATE COMMENT 'Date of instrument',
        transaction_type VARCHAR(50) COMMENT 'Cheque, RTGS, UPI, etc.',
        bank_name VARCHAR(100) COMMENT 'Bank name',
        ledger_name VARCHAR(100) COMMENT 'ledger_name',
        payment_favouring VARCHAR(150) COMMENT 'Payee name',
        cheque_cross_comment VARCHAR(150) COMMENT 'Crossed cheque comment (if any)',
        ifsc_code VARCHAR(20) COMMENT 'IFSC code',
        bank_party_name VARCHAR(150) COMMENT 'Party’s bank account name',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated',
        -- Foreign key linking to vouchers table
        CONSTRAINT fk_voucher_bank_allocations_voucher_id FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'Bank allocation details for voucher';

CREATE TABLE
    voucher_gst_details (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_id BIGINT COMMENT 'FK to vouchers.id',
        tax_rate VARCHAR(50) COMMENT 'tax_rate.',
        gst_registration_type VARCHAR(50) COMMENT 'Regular, Composition, etc.',
        gstin VARCHAR(50) COMMENT 'GSTIN number',
        hsn_code VARCHAR(20) COMMENT 'HSN code',
        cgst_amount DECIMAL(15, 2) COMMENT 'CGST component amount',
        sgst_amount DECIMAL(15, 2) COMMENT 'SGST component amount',
        igst_amount DECIMAL(15, 2) COMMENT 'IGST component amount',
        taxable_amount DECIMAL(15, 2) COMMENT 'Taxable value',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated',
        -- Foreign key constraint
        CONSTRAINT fk_voucher_gst_details_voucher_id FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'GST tax breakup (optional)';

CREATE TABLE
    voucher_tds_entries (
        id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT 'Internal ID',
        voucher_id BIGINT COMMENT 'FK to vouchers.id',
        tds_deductee_name VARCHAR(150) COMMENT 'Deductee name',
        tds_amount DECIMAL(15, 2) COMMENT 'TDS amount',
        assessable_amount DECIMAL(15, 2) COMMENT 'assessable_amount',
        tds_section_name VARCHAR(50) COMMENT 'Section name',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp inserted',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp updated',
        -- Foreign key constraint to link with vouchers table
        CONSTRAINT fk_voucher_tds_entries_voucher_id FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = 'TDS details (if applicable)';