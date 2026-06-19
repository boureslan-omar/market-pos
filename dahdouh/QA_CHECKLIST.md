# MARKET POS — QA CHECKLIST v3.1.0
**Tested by:** ___________________________  
**Test date:** ___________________________  
**Version confirmed in system:** ___________________________  
**Exchange rate used during testing:** $1 = LL ___________

---

## HOW TO GRADE

| Symbol | Meaning |
|--------|---------|
| ✅ | **PASS** — worked exactly as expected |
| ❌ | **FAIL** — wrong result, error, or crash |
| ⚠️ | **PARTIAL** — mostly works but one detail is wrong |
| ⏭️ | **SKIP** — not applicable to this installation |

**Required fields for every non-PASS result:** write what happened, what value appeared, and any error text in the Notes column.  
Collect all ❌ / ⚠️ rows in the **Issue Log** at the end of this document.

**Before starting:** Run `http://localhost/dahdouh/upgrade_all.php` once to ensure all schema upgrades are applied.

---

---

# MODULE 1 — AUTHENTICATION & USERS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 1-01 | Open `http://localhost/dahdouh/` without logging in | Redirect to login page |pass | |
| 1-02 | Log in with **admin / admin123** | Dashboard loads, shows admin name in navbar |pass | |
| 1-03 | Go to Settings → Users → Add new cashier user (username: `test_cashier`, password: `pass123`, role: Cashier) | User saved successfully |pass | |
| 1-04 | Log out, log in as `test_cashier` / `pass123` | Cashier dashboard loads; admin-only links (Reports, Users, Settings) are hidden |pass | |
| 1-05 | As cashier, try to navigate directly to `/dahdouh/pages/reports.php` | Access denied or redirect to login |pass | |
| 1-06 | Log back in as **admin** | Dashboard loads correctly |pass | |

---

# MODULE 2 — PRODUCTS & CATEGORIES

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 2-01 | Go to Products → Add Category → enter "Test Category" | Category appears in list and in Add Product dropdown | pass| |
| 2-02 | Add Product: name="Test Product A", barcode auto-generated, category="Test Category", cost=5.00, sell=8.00, unit=pcs, stock=50 | Product saved, appears in list | pass| |
| 2-03 | Add Product with units_per_box=12, box sell price=80.00 | Product saved with box pricing |pass | |
| 2-04 | Toggle "Set by margin %" on sell price field → enter 30% | Sell price auto-calculates to cost ÷ (1 − 0.30) | pass| |
| 2-05 | Edit an existing product → change sell price → Save | New price shows in product list |pass | |
| 2-06 | Search/filter the products list by name or barcode | Results narrow correctly | pass| |
| 2-07 | Add a CONSIGNMENT product: source=Consignment, consignment cost=3.00, sell=6.00, supplier=any | Product saved with product_source='consignment' |pass | |
| 2-08 | Add a BULK product: product_type=bulk | Product saved |pass | |
| 2-09 | Delete a product with no sales history | Product removed from list |pass | |

---

# MODULE 3 — POS / SALES

> Run these tests in order. Note the receipt number for each sale — you'll need them later.

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 3-01 | Open POS → add "Test Product A" (qty 2) to cart | Cart shows: qty=2, unit price=8.00, line total=16.00 |pass | |
| 3-02 | Apply $2 discount → check totals | Subtotal=16.00, Discount=2.00, Total=14.00 |pass | |
| 3-03 | Pay exact $14.00 USD cash → complete sale | Receipt shown; cash register log has +$14.00 sale entry |pass | |
| 3-04 | Check Products: "Test Product A" stock decreased by 2 | Stock went from 50 → 48 | pass| |
| 3-05 | Check batches table (or FIFO): batch quantity_remaining decreased | FIFO batch was consumed |pass | |
| 3-06 | New sale: add product, pay in LBP only (e.g. LL 900,000 for $10 item at current rate) → complete | Sale completes; cash register shows +LBP entry, $0 USD |pass | |
| 3-07 | New sale: pay part USD + part LBP (mixed) → complete | Sale completes; cash register shows both USD and LBP amounts |pass | |
| 3-08 | New sale: select payment method = **Credit** (no cash) | Sale saved; no cash register entry for this sale |pass | |
| 3-09 | Create a customer "Test Customer" with starting balance $0 | Customer saved |pass | |
| 3-10 | Make a sale assigned to Test Customer, pay cash in full ($20) | Customer balance unchanged ($0); cash register +$20 | pass| |
| 3-11 | Make a $30 credit sale to Test Customer (no cash paid) | Customer balance = **−$30** (they owe the store $30) |pass | |
| 3-12 | Make a partial cash payment: manually add $10 cash payment to customer account via Customers page | Customer balance = **−$20** |pass | |
| 3-13 | Make a $25 sale to Test Customer; apply $10 store credit from their account (credit_use=10); pay remaining $15 cash | Balance changes by −10 (credit consumed) → new balance = **−$30**; cash register +$15 |pass | |
| 3-14 | Open the receipt from test 3-13 → verify receipt shows correct totals (subtotal, credit used, total paid) | All values match |pass | |

---

# MODULE 4 — CASH REGISTER

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 4-01 | Open Cash Register page → note current USD balance | Balance displayed correctly |pass | |
| 4-02 | Perform a **USD Withdrawal** of $10.00 with note "Test withdrawal" | Balance decreases by $10; log shows withdrawal entry |pass | |
| 4-03 | Perform a **USD Deposit** of $5.00 | Balance increases by $5; log shows deposit entry |pass | |
| 4-04 | Perform a **LBP Withdrawal** of LL 50,000 | LBP balance decreases; USD balance unchanged |pass | |
| 4-05 | Set Opening Balance USD to a specific amount (e.g. $200) | Balance adjusts by the difference; log shows opening entry | pass| |
| 4-06 | View the log for today → confirm all entries from tests 3-03, 3-06, 4-02, 4-03, 4-04 appear | All entries visible with correct amounts and signs |partial |the amounts and transaction done on the account are not shown on the cahsregister logs |
| 4-07 | Check: sale entries are **+** (positive); withdrawal entries are **−** (negative) | Signs are correct throughout |pass | |
| 4-08 | Click **End of Shift** → fill in shift note → submit | Shift saved; shift history shows the snapshot with correct balance and counts |pass | |
| 4-09 | After shift close: make a new sale. Open shift again — it should show only movements SINCE the last close | Shift stats reset; only new movements counted |pass | |
| 4-10 | Perform a sale, then **void** it (from Reports → find sale → Void) | Cash register shows a void entry that exactly reverses the sale amount |pass | |
| 4-11 | Void a **credit sale** (no cash was paid) | Cash register: no entry added (nothing to reverse); customer balance restored |pass | |
| 4-12 | Void the sale from test **3-13** (credit-used sale: $10 credit + $15 cash) | Cash register: −$15 void entry; customer balance restored to −$20 (credit of $10 returned) | partial|the cash amount didint record in the cahs register as returned but the credit worked fine  |

---

# MODULE 5 — PURCHASES

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 5-01 | Go to Purchases → New Purchase → select supplier → add a REGULAR product row, enter qty=10, cost=4.00, sell=7.00, total line=$40.00 | Row calculates correctly |pass | |
| 5-02 | Add a CONSIGNMENT product row (toggle type to Consignment) in same purchase | Line total shown separately under "Consignment" total; does NOT add to Amount Due | pass| |
| 5-03 | Add a BULK product row (toggle type to Bulk) in same purchase | Qty field disabled; bulk row calculates correctly |pass | |
| 5-04 | Set payment method to Cash Register → Save Purchase | Purchase saved; supplier balance increases by (regular-only total, not consignment total); cash register shows withdrawal for regular amount only |pass | |
| 5-05 | Check stock: regular product stock increased; consignment product stock increased | Both stocks updated |pass | |
| 5-06 | Check batches: regular product has a new batch; consignment product — check that its batch was created (for inventory tracking only) | Correct batch behavior per type |pass | |
| 5-07 | Check supplier ledger: only the regular product amount appears as a purchase debt | Consignment NOT in supplier balance |pass | |
| 5-08 | Delete the purchase just created | Stock reversed for all items; supplier balance restored (regular portion only); batches restored |parial |the stock reversed but the amount of the purcahse is not calculated as a refund or shown in the legder of the cahs register |

---

# MODULE 6 — PURCHASE ORDERS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 6-01 | Go to Purchase Orders → New PO → select supplier → add 2 items → Save | PO saved with auto-number (PO-YYYY-NNNN) |pass | |
| 6-02 | Change PO status Draft → Sent → Confirmed | Status updates correctly at each step |pass | |
| 6-03 | Click Receive → check quantities pre-filled → edit a cost → click "Commit Receipt" | PO status → Received; stock updated; purchase record created; batch created |pass | |
| 6-04 | Click Print / WhatsApp on a PO | Print dialog opens; WhatsApp message generated with PO details |pass | |
| 6-05 | Cancel a separate PO using status → Cancelled | PO marked Cancelled; Receive button hidden |pass | |
| 6-06 | Delete a Draft PO | PO removed from list | pass| |

---

# MODULE 7 — SUPPLIERS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 7-01 | Add a new supplier with name, phone, email | Supplier appears in list |pass | |
| 7-02 | After a purchase (Module 5), check supplier balance = amount owed | Balance shows correct total |pass | |
| 7-03 | Record a **cash USD payment** to supplier (e.g. $20) | Supplier balance decreases by $20; cash register shows −$20 withdrawal |pass | |
| 7-04 | Record a **cash LBP payment** to supplier | Balance note updated; cash register shows LBP withdrawal |fail |i need to add the amount in lbp and to after selecting the deduct from the lbp and the amounts are not being calculated correctly as it is working now  |
| 7-05 | View supplier ledger — check all entries are present (purchase, payments) | Full history visible |pass | |
| 7-06 | Make a **supplier return** (Returns page → Supplier Returns tab) — select a batch, enter qty and note | Supplier return recorded; batch quantity_remaining increases; supplier balance decreases (credit given) | fail|the amount is deducted from product but about the money nothing changed you need to work on it to solve  |

---

# MODULE 8 — CUSTOMERS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 8-01 | Add a new customer with name, phone, opening balance $0 | Customer saved |pass | |
| 8-02 | View customer ledger — shows all transactions from test Module 3 | All sale, payment, and adjustment entries visible | pass| i need to be able to print the reciept if i want |
| 8-03 | Record a direct payment from customer (e.g. $50 cash payment to reduce debt) | Balance adjusts correctly; payment entry in ledger |pass | pass|
| 8-04 | **Credit use test:** Customer has +$30 balance (pre-pay scenario). Make a $20 sale and apply $20 store credit (credit_use=20, no cash). | Customer balance: +$30 → **+$10** (credit consumed). Cash register: NO entry. |fail | but it is not recording on the cashregister i want it to be recorded |
| 8-05 | **Void the credit-use sale from 8-04.** | Customer balance: +$10 → **+$30** (restored). Cash register: no reversal (none was made). |fail | i want to track all cash payments now it is not recorded|
| 8-06 | **Partial credit test:** Customer has +$30. Make $50 sale: apply $30 credit + pay $20 cash. | Balance: +$30 → **$0**. Cash register: +$20 USD. |fail | i want to track all cash payments now it is not recorded |
| 8-07 | **Void the sale from 8-06.** | Balance: $0 → **+$30** (restored). Cash register: −$20 (cash returned). | |void for cash customers are shown in ledger normally  |

---

# MODULE 9 — EXPENSES

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 9-01 | Add a USD expense ($15) with **Deduct from Cash Register** checked | Expense saved; cash register shows −$15 expense entry | pass| |
| 9-02 | Add a LBP expense (LL 200,000) with **Deduct from Cash Register** checked | Cash register shows LBP expense entry | pass| |
| 9-03 | Add an expense with **Deduct from Cash Register** unchecked | Expense saved; cash register has NO new entry |pass | |
| 9-04 | Check expenses on the dashboard stats for today | Expense totals match what was entered | pass| |

---

# MODULE 10 — RETURNS

## 10A — Customer Returns

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 10A-01 | Go to Returns → Customer Returns tab → search a receipt number from Module 3 | Sale items listed with quantities and prices | pass| |
| 10A-02 | Select one item, enter return qty = 1, submit | Return recorded; stock +1 for that product; cash register shows refund entry (negative amount) | pass| |
| 10A-03 | Try to return MORE than was originally sold | System blocks it — error or qty capped | pass| |
| 10A-04 | Return an item a second time — the already-returned quantity should be excluded | Max returnable quantity reduced correctly | pass| |
| 10A-05 | Check Recent Returns log at bottom of the page — new entry appears | Entry shows product, qty, refund amount | pass| |

## 10B — Supplier Returns

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 10B-01 | Go to Returns → Supplier Returns tab → search for a product batch | Batches listed with batch ID, cost, quantity remaining |pass | |
| 10B-02 | Select a batch, enter return qty and note → submit | Return recorded; batch quantity_remaining increases; supplier balance credited (decreases debt) | pass| |
| 10B-03 | Check supplier ledger — return entry appears with credit amount | Entry visible |pass | |
| 10B-04 | Check Recent Supplier Returns log | Entry appears correctly |pass | |

---

# MODULE 11 — AMENITIES / CONSIGNMENT SETTLEMENT

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 11-01 | Go to Amenities → select a consignment supplier → view pending amount (sum of unpaid consignment sales) | Correct total shown | pass| |
| 11-02 | Record a **Cash Register** payout to consignment supplier | Cash register shows withdrawal; consignment entries marked settled |pass | |
| 11-03 | Record an **Owner Cash** payout | Cash register shows deposit (owner-funded); entries marked settled | pass| |
| 11-04 | After payout: pending amount resets to $0 | Settled correctly |pass | |

---

# MODULE 12 — REPORTS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 12-01 | Open Reports → select today's date | Shows sales list for today | pass| |
| 12-02 | Select a date range (e.g., last 7 days) | Sales for the entire range shown |pass | |
| 12-03 | Revenue / COGS / Gross Profit figures look correct for the period | Math checks out (Revenue − COGS = Gross) |pass | |
| 12-04 | Voided sales appear strikethrough / marked void | Voided sales clearly labeled; excluded from totals | pass| |
| 12-05 | Print / export of sales list works | Printable view opens | pass| |

---

# MODULE 13 — BACKUP

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 13-01 | Go to Backup page (Admin → Backup) | Page loads without error |pass | |
| 13-02 | Click **Backup Now** | Backup files created (db_YYYY-MM-DD.sql + files_YYYY-MM-DD.zip); appear in backup list | pass| |
| 13-03 | Download the SQL backup file | File downloads and is non-empty | pass| |
| 13-04 | Delete an old backup file from the list | File removed | pass| |

---

# MODULE 14 — SETTINGS

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 14-01 | Change store name → save | New name shows in navbar and receipt header |pass | |
| 14-02 | Change exchange rate (e.g., 89750 → 90000) → save | POS uses new rate for LBP conversions | pass| |
| 14-03 | Change theme color → save | UI color changes throughout |pass | |
| 14-04 | Go to Settings → Users → deactivate test_cashier | User cannot log in (inactive) | pass| |

---

# MODULE 15 — FINANCIAL INTEGRITY CHECKS

> These are cross-checks to verify the numbers are consistent. Do these LAST after all other modules.

| # | Check | How to Verify | Expected | Grade | Notes |
|---|-------|---------------|----------|-------|-------|
| 15-01 | **Cash register balance = sum of all log entries** | Compare "USD Drawer" balance on Cash Register page vs manually summing all amount_usd entries | Must match exactly | partial| all the amounts are correct except when the customer add credits i need it to be mentioned in the ledger|
| 15-02 | **LBP Drawer same check** | Same as above for LBP | Must match exactly | partial | all the transactions are correct but we need to add that the customer can close the debt in lbp and can add to his credit as lbp|
| 15-03 | **Shift In/Out adds up** | Cash In USD − Cash Out USD on current shift = net cash movement since last shift close | Matches actual entries |pass | |
| 15-04 | **Customer balance after credit-use sale** | Make sale with $20 credit use on $30 purchase: customer pays $10 cash. Customer balance must DECREASE by $20 (credit consumed). | Balance −$20, NOT +$20 | partial| but i need it to add the 10$ as a cash payment in the ledger
| 15-05 | **Void of credit-use sale restores balance fully** | Void the sale from 15-04 | Balance restored to pre-sale value exactly |pass
| 15-06 | **Consignment supplier balance NOT affected by purchase** | After receiving consignment products, check supplier balance | Supplier balance unchanged |pass
| 15-07 | **Consignment sale triggers consignment ledger entry** | Sell a consignment product via POS | Consignment ledger entry shows revenue, supplier_due, market_profit |pass
| 15-08 | **FIFO batch deduction on regular sale** | Sell regular product → check batch quantity_remaining decreased | quantity_remaining reduced by sold qty |pass
| 15-09 | **Refund reduces cash register** | Process customer return (Module 10A) → check cash register | Cash register shows negative refund entry = refund amount |partial| the amount of stock is increased after the refund but the cash money it is not showing in the ledger
| 15-10 | **Supplier return credits supplier** | Process supplier return (Module 10B) → check supplier balance | Balance decreases by credit amount |partial|  the amount of stock is decreased after the refund but the cash money it is not showing in the ledger also you need to fix the return because it is taking into consideration the whole batch or whole amount but the amount that is refunndable is decreasing becuase i sold items from the batches its considring the whole amount even though i dont have this amount in my stock

---

# MODULE 16 — LICENSE & ACTIVATION

> These tests must be run on a FRESH installation where no `license.lic` file exists yet. If testing on an already-activated machine, temporarily rename `license.lic` to `license.lic.bak` and restore it after.

| # | Test | Expected | Grade | Notes |
|---|------|----------|-------|-------|
| 16-01 | With no license.lic present, open any system page (e.g. `/dahdouh/pages/pos.php`) while logged in | Redirected to `/dahdouh/pages/activate.php?reason=...` |pass | |
| 16-02 | On the activation page, confirm the Machine ID is displayed in `XXXX-XXXX-XXXX-XXXX` format | Machine ID shown — copy it for next step |pass | |
| 16-03 | On the developer machine open `http://localhost/dahdouh/tools/keygen.php` → enter the Machine ID and a client name → Generate | A license key (long Base64 string) is generated | pass| |
| 16-04 | Paste the generated key into the activation page → click Activate | Success message shows the client name; redirects to login |pass | |
| 16-05 | Log in and navigate normally — no activation prompt appears | System works normally; license cached in session |pass | |
| 16-06 | Log out and log back in | No activation prompt; session re-validated from license.lic |pass | |
| 16-07 | Copy the `license.lic` file to a DIFFERENT machine and open the system there | Activation page appears — license is rejected ("invalid or belongs to a different machine") |pass | |
| 16-08 | On the keygen page, paste a random / garbage key → Generate | The key is generated but won't validate on a machine whose ID doesn't match |pass | |
| 16-09 | On the activation page, paste a tampered key (change one character) → Activate | "Invalid license key" error; system not activated |pass | |
| 16-10 | Try to access `http://localhost/dahdouh/tools/keygen.php` from a device on the network (not localhost) | 403 Access Denied | pass| |

---

---

# ISSUE LOG

also we need to fix these 
- after a batch is totally sold remove it from the batch lists there is no need to keep it after it is totally sold.
-also i need to able to edit the batch
-i need to be able to edit a purchase
-in the discount section in pos sales i need to keep it like it is now but also i want to be able to check a box if i want to make the discount in percentage not as an amount.
- we need to be able to restore from a backup.
- also we need to add in the settings a part where we can see ourthe license of this store.
- in keygen i want to add somthing where i can see the isssued licenses and for which client 
## SIGN-OFF

| | |
|--|--|
| **Tester signature:** | ___________________________ |
| **Total tests:** | ___ |
| **PASS:** | ___ |
| **FAIL:** | ___ |
| **PARTIAL:** | ___ |
| **SKIP:** | ___ |
| **Pass rate:** | ___% |
| **Ready for client deployment?** | YES / NO |
| **Notes for developer:** | |
