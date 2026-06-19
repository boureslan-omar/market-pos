# Testing Checklist — v3.2.0 Round 2
**Run upgrade13.php first before testing: http://localhost/dahdouh/upgrade13.php**

---

## 1. Hold Sale (POS)
- [x] Add items to cart → click Hold Sale → cart clears
- [x] Click Resume Sale → previous cart restores with correct items/quantities
- [x] Hold a sale, close browser, reopen POS → sale still resumes

---

## 2. Debt Settlement at Checkout (POS)
- [x] Customer with existing debt → at checkout, debt amount field appears
- [x] Enter a payment amount that covers sale + partial debt → debt is reduced correctly
- [x] After checkout, open customer profile → ledger shows a "Debt settlement" entry (not just "Payment for receipt")
- [ ] Print receipt → receipt shows a "Debt Settled" line (in red) listing the amount paid toward old debt

---

## 3. Edit Receipt — Reports Tab
- [x] Go to Reports → find a sale → click the pencil (✏) button
- [x] Edit modal opens with correct items, quantities, and prices
- [x] Change a quantity → New Total updates live
- [x] Change a price → New Total updates live
- [x] Click trash on an item → item removed, New Total recalculates
- [x] Click Add Product → type a product name → results appear with name + barcode + price
- [x] Scan a barcode in the Add Product field → exact match auto-selects immediately
- [X] Pick a product → it appears as a new row in the edit table
- [x] Save → receipt total updated, stock adjusted correctly
- [x] Void receipt still works (not broken by edit feature)

---

## 4. Print Receipt — Reports Tab
- [x] Go to Reports → find any sale (voided or not) → click the printer (🖨) button
- [x] Receipt modal opens and loads the correct receipt
- [x] Receipt shows store name, date, items, totals, paid amounts
- [ ] Click Print → a small popup window opens showing only the receipt → browser print dialog opens for that popup
- [x] Voided sale shows "VOIDED" badge on the receipt

---

## 5. Edit Receipt — Customer Tab
- [x] Go to Customers → open a customer profile → find a receipt row
- [x] Non-voided receipts show a pencil (✏) button — voided ones do NOT
- [x] Click pencil → Edit modal opens with the correct items
- [x] Change a quantity → New Total updates live
- [x] Remove an item → New Total recalculates
- [x] Add a product by typing name or scanning barcode → exact barcode auto-selects
- [x] Save → receipt updated, page reloads showing new total
- [ ] Print button (🖨) still works on the same row after editing → popup opens showing only the receipt

---

## 6. Print Receipt — Customer Tab (existing feature, verify not broken)
- [x] Go to Customers → open a customer profile → click printer (🖨) on a receipt
- [ ] Receipt loads and Print button opens a popup with only the receipt → browser print dialog for that popup

---

## 7. Edit Purchase (Purchases)
- [x] Go to Purchases → find a received PO → click the pencil (✏) button
- [x] Edit modal opens with correct items and quantities
- [x] Change a quantity → saves correctly, batch quantity_remaining updated
- [x] Save → supplier balance or cash register adjusted for the difference

---

## 8. Owner Cash Tracker (Cash Register)
- [x] Make a cash deposit → appears in Owner Cash section as unsettled
- [x] Click Withdraw/Settle → entry marked settled
- [x] After settling, the Withdraw button disappears (does not reappear)
- [x] Settled entries show in the settled history

---

## 9. Supplier Return Cash Refund (Returns)
- [x] Create a supplier return → two options appear: "Credit to Balance" and "Cash Refund from Supplier"
- [x] Select Credit to Balance → supplier balance increases
- [x] Select Cash Refund from Supplier → cash register deposit entry created (not supplier balance)

---

## 10. Suppliers LBP Payment
- [ ] Go to Suppliers → make a payment → Payment Method dropdown shows "Cash Register (USD)" and "Cash Register (LBP)" as visible options
- [ ] Select "Cash Register (LBP)" → it actually selects (not hidden/disabled)
- [ ] Payment saves in LBP correctly

---

## 11. Box Products — POS Tile Display
- [x] Product with 10 boxes × 10 units (100 total units in stock):
  - [x] Green stock line shows "10 box · 100 units" (not "100 box · 10 box")
  - [x] Low stock warning also shows "X box · Y units"
- [x] Product with no box grouping shows normal "50 pcs" style

---

## 12. Box Products — POS Tile Button
- [X] Box product tile shows a "📦 Box ×N — $X.XX" button
- [x] Clicking the box button adds the correct quantity to cart at the wholesale box price

---

## 13. Quick Add New Product (from Purchase form)
- [X] Click "+ Add Item" on a new purchase → type a product that doesn't exist → click "+" button
- [x] Modal opens as a full-size form (not the old small form)
- [x] **Product Source**: select Owned → consignment fields hidden
- [x] **Product Source**: select Consignment → supplier + cost fields appear
- [x] **Unit badge**: pcs/box → badge shows "Regular"; kg/g/L/mL → badge shows "Bulk"
- [x] **Unit = box**: Box Details section appears (Units per Box, Cost per Box, Sell per Box Wholesale)
- [x] **Unit = box**: "Sell per Unit (Retail)" field also appears separately from wholesale
- [x] **Unit = box**: Cost per unit auto-calculates from cost-per-box ÷ units
- [x] **Unit = box**: Retail and Wholesale sell prices are independent (changing one does NOT change the other)
- [x] Bulk products (kg/g): Initial Stock and Low Stock Alert fields are hidden
- [x] Regular products: Initial Stock and Low Stock Alert fields visible and save correctly
- [x] Click Create & Add → no network error, product created and added to purchase row
- [x] Category "+" button inside the modal works (creates new category inline)

---

## 14. Upgrade Script (upgrade13.php)
- [x Run http://localhost/dahdouh/upgrade13.php
- [ ] All blocks complete without errors:
  - Block 1: cash_register_log.settled_by
  - Block 2a: products.units_per_box
  - Block 2b: products.sell_price_box
  - Block 3: api.php — cost_price added to edit_sale batch SELECT
  - Block 3b: cash_register_log.type ENUM (adjustment added)
  - Block 4: suppliers.php — Cash Register (LBP) option made visible
  - Block 5: api.php — debt_settled added to sale_receipt response
  - Block 6: version.json updated to v3.2.0
- [x] Re-running shows "already exists, skipped" for schema blocks (idempotent)

---

## Notes for Tester
- If any item **fails**, note the exact steps to reproduce
- If behaviour is **confusing or unclear** even if technically correct, note it
- Test on a real dataset with actual products/suppliers, not dummy data
- Box product tests require a product with units_per_box > 1 set up first
- Sections 3, 4, 5, 6 all involve receipts — test them together on the same sale for efficiency
