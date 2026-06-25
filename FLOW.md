# Matria Marine — How the System Works (The Flow)

> A plain-English walkthrough of the whole process, from a customer asking for
> goods to the vendor delivering them. Read this before a demo or meeting.
> Matria is a **ship chandler**: a customer (a vessel / ship manager) needs
> goods, Matria sources them from vendors, marks them up, and resells them.

---

## The cast (who's who)

- **Customer** — the vessel or ship-management company that needs goods.
  They contact Matria **directly** (phone / email). Matria's staff records it.
- **Vendor** — a supplier Matria buys from.
- **Matria** — the middle-man. Buys low from vendors, adds a **markup**, sells
  to the customer. The markup is Matria's profit.

---

## The flow in one line

**Customer asks → Matria enquires vendors → vendors quote (base price) →
Matria marks up → sends Offer to customer → customer accepts →
Matria issues a Delivery Order (DO) + Purchase Order (PO) → vendor delivers
to the customer.**

---

## Step by step

### 1. Set up master data (once, then as needed)
- **Add Customers** — name, address, currency. (Portal → Customers)
- **Add Vendors** — name, contact, email, currency. (Portal → Vendors)

### 2. A customer asks for goods → create an **Enquiry**
The customer contacts Matria directly. Staff opens **Portal → Enquiries → New**
and records:
- which **Customer** is asking (+ their reference number),
- the **items** they want (description, qty, unit),
- requirements (e.g. *Genuine spares only, Class certificate, MSDS*),
- equipment details (maker / type / serial), priority, any attached files.

> This is the front of the funnel — "what does the customer want?"

### 3. Matria asks vendors for prices → **send the RFQ**
From the enquiry, Matria picks one or more **vendors** and sends them a request.
Each vendor gets a private **magic link** (no login needed) where they type
their prices. This is the **vendor's quotation = their base price**.

### 4. Vendors reply → compare their **base prices**
As vendors submit, Matria sees a side-by-side **comparison grid** of who quoted
what for each line, converted to one currency.

### 5. Matria marks up → builds the **Offer (Quotation to the customer)**  ⭐
This is the heart of the business. For each line, Matria takes the vendor's
**base price** and adds a **markup %** (e.g. 5%, 10%):

```
Base price (from vendor)   619.16
Markup %                   + 10%
─────────────────────────────────
Unit price (to customer)   681.07   ← this is what the customer sees
Markup amount (profit)      61.92
```

The Offer also carries: validity, **payment terms** (e.g. Net 30), **delivery
terms / Incoterms** (EXW, FOB, CIF, DDP…), **origin type** (Genuine / OEM /
Aftermarket / Used), currency, lead time, delivery location, remarks.
Matria sends the customer a clean **Quotation PDF** — vendor names and base
prices are **never** shown, only the marked-up price.

### 6. Customer accepts
The customer reviews and accepts the offer.

### 7. On acceptance → **Delivery Order (DO)** + **Purchase Order (PO)**
Two documents are created from the accepted offer:
- **Delivery Order (DO)** — here Matria enters the **customer's delivery
  address** (which vessel / port to deliver to).
- **Purchase Order (PO)** — sent to the vendor, telling them what to supply
  **and where to deliver it** (the customer's address from the DO). The vendor
  ships the goods straight to the customer.

> No separate invoice page. Billing info lives on the DO / PO.

---

## Glossary — our words vs. Crown's words

| Crown (Helios)        | Matria term            | What it is                                  |
|-----------------------|------------------------|---------------------------------------------|
| Sales Inquiry         | **Enquiry**            | The customer's request to Matria            |
| (vendor sourcing)     | **RFQ / Vendor quote** | Matria asking vendors for base prices       |
| Sales Offer           | **Offer / Quotation**  | Marked-up prices sent to the customer       |
| Sales Order (Delivery)| **Delivery Order (DO)**| Confirmed order + customer delivery address |
| Purchase Order        | **Purchase Order (PO)**| Order sent to the vendor                     |

---

## Key idea to remember in a demo

> Matria buys at the **vendor's base price**, adds a **markup %**, and sells at
> the **customer's unit price**. The difference is the profit (the *markup
> amount*). The customer only ever sees the marked-up price — never the vendor
> or the base price.

---

## Build status (for the developer)

- ✅ Customers, Vendors (master data)
- ✅ Enquiries / RFQ (+ customer, requirements) → vendor magic-link quotes → comparison grid (vendor totals + complete/incomplete)
- ✅ **Markup → Offer/Quotation to customer** — base price → markup % → customer price, profit shown, Quotation PDF (customer never sees the vendor/base price)
- ✅ **Delivery Order (DO)** — created from an accepted offer; holds the customer **delivery address**; **Generate POs** stamps that address onto the vendor purchase order
- ✅ Purchase Orders (with vendor magic-link acceptance) — now carry the customer delivery address
- 🗑️ Purchase Invoice + Navision export — **removed** (accounting deferred)
