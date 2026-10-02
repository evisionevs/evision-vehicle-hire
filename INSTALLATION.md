# EVision booking

Custom vehicle-hire plugin for WordPress and WooCommerce. Version 0.1.7 is a **staging test release**, not a security-certified or live-shop-verified release.

The installable plugin is the `evision-vehicle-hire` directory. The `tests` directory is for development and is excluded from the plugin ZIP.

## Installation on staging

1. Back up the site and use a staging copy with payment gateways in test mode.
2. Upload `evision-vehicle-hire-0.1.7.zip` under **Plugins → Add New → Upload Plugin**, then activate it. To update an earlier version, choose **Replace current with uploaded**. Existing reservations and saved prices are retained; values discarded by the old version must be entered again.
3. On the staging copy, deactivate **Easy Booking**. Its data is not deleted or automatically imported. This plugin pauses online quoting while the standard Easy Booking plugin is active, to avoid competing price calculations.
4. Use classic basket and checkout pages containing `[woocommerce_cart]` and `[woocommerce_checkout]`, rather than WooCommerce Cart/Checkout Blocks. The product page must use the standard simple-product WooCommerce add-to-basket form. Test any page builder or theme customisation.
5. Enable tax calculations under **WooCommerce → Settings → General**. Under **WooCommerce → EVision hire**, click **Create dedicated 20% hire VAT class**. This adds an isolated EVision tax class and an unrestricted 20% rate, without changing existing tax classes or globally changing how you enter other product prices.
6. Edit a simple vehicle product. In **Product data → EVision hire**, enable vehicle hire and enter fleet quantity, notice, six prices excluding VAT, minimum driver age and licence duration. Save the product.
7. Refresh England bank holidays under **WooCommerce → EVision hire**. Add your own closure dates.
8. Use the booking ledger to enter existing Easy Booking, MCS, telephone and maintenance reservations before allowing live bookings.
9. Complete the staging acceptance checks below. Do not install on the live shop until booking and payment behaviour has been reviewed with the real gateway and theme.

Requirements: WordPress 6.5+, WooCommerce, PHP 8.1+, GBP currency, InnoDB, and MySQL 5.7.5+ or MariaDB 10.1+ with `GET_LOCK`/`RELEASE_LOCK` available. Modern maintained WordPress/WooCommerce and database versions are preferred. No npm, Composer, API licence or subscription plugin is needed to run it.

## Vehicle settings

One product represents a vehicle type. Set quantity to one for a unique car, or to the number of interchangeable vehicles if several are available. The site does not assign individual registration numbers.

| Hire length | Price entered, excluding VAT | Effective daily rental rate |
|---|---|---|
| 1–6 days | Daily price | Entered price |
| 7–29 days | Weekly price | Weekly price ÷ 7 |
| 30–364 days | Monthly price | Monthly price × 12 ÷ 365 |
| 365–729 days | Monthly price | Monthly price × 12 ÷ 365 |
| 730–1094 days | Monthly price | Monthly price × 12 ÷ 365 |
| 1095+ days | Monthly price | Monthly price × 12 ÷ 365 |

The applicable band prices the **entire hire**. The plugin accepts up to 3,650 chargeable days, within a ten-year booking horizon. Monday collection to Tuesday return is one day. Same-date hire has a one-day minimum; where both times are specified, return must follow collection. Times do not add extra rental days.

Rates can be up to 999,999.99. All six fields must contain positive values when enabling hire. You can save prices in stages while hire is disabled. Valid prices are retained even if another setting is incomplete or invalid; online booking stays disabled until setup is valid. Do not use WooCommerce sale prices for these products. The plugin makes enabled hire products virtual and disables ordinary stock reduction, using its own date-based fleet capacity instead. Disabling hire keeps the vehicle unavailable for purchase and does not restore its previous price or stock settings. Converting a hire product back into a conventional sale product requires a separate configuration change.

The minimum age can be **21+, 25+ or 30+**. Licence duration can be **3 or 5 years**. Choose the correct combination for each model. The page displays these requirements, the maximum six licence points and the insurance restrictions for couriers, chauffeurs and third-party hire businesses. Selecting insurance requires an eligibility acknowledgement. This acknowledgement is not identity or licence verification.

## Notice and opening hours

All scheduling uses **Europe/London**, including daylight savings. Set WordPress's own timezone to Europe/London too, so order and admin timestamps are consistent.

Office hours are Monday–Friday, 08:00–18:00. Same-day booking is available only while the office is open and **before 12:00**. The selected appointment must be later than the current time. Per-product collection notice can be zero, one, two, three, four, five or ten **working days**. Zero means same-day eligible, subject to opening hours and cut-off.

The booking date does not count as a notice day. Requests made while the office is closed start processing on the next office day and allow at least one further working day before collection. Examples:

- Friday in office hours, one-day notice: Monday, unless Monday is a closure.
- Sunday, even for a same-day-enabled vehicle: Tuesday, unless Monday/Tuesday is a closure.
- Thursday after 18:00, same-day-enabled vehicle: Monday, allowing Friday for preparation.

Saturday appointments must use **Out of hours: team to arrange**. Weekday out-of-hours appointments use the same option, without committing the team to a specific time. Each collection or return request requires **two working days' notice**, adds £25 excluding VAT by default, and remains subject to agreement. Staff contact the customer to agree the time. This version does not offer an automatic confirmation button or update the agreed time in the reservation ledger; record the agreed appointment in the order notes and communicate it to the customer.

Sundays, England and Wales bank holidays and additional closure dates block collection/return. A hire can continue through them. The unavailable-date message appears when a customer selects a closed collection or return date.

## Quotation display

The payable total stays visible while scrolling within the booking form. It updates from server quotations as dates, payment mode and insurance options change. Pending updates are labelled; invalid dates do not retain a misleading payable amount. Each selected component has one itemised row. Insurance is grouped, such as `10 × £4.00 per day`, with the extended amount alongside it. Out-of-hours rows simply explain that the team will arrange the time. Late-return/grace-period terms are not modified by this display update.

The page includes a delivery-at-checkout notice. This notice does not create a delivery option or fee: the shop needs an existing checkout delivery option, or a separate delivery-pricing feature.

## Long-term payments and insurance

Under 30 days: full payment online. At 30 days or more: choose full payment or first-month payment.

- Full rental value = effective daily rate × chargeable days.
- Monthly rental = effective daily rate × 365 ÷ 12.
- First rental payment = the lower of the monthly rental or full rental value.
- Collection/return fees are charged in full online in either payment mode.
- The order reserves fleet capacity for the entire hire period in either payment mode.

The first-month quotation and order explain that accounts will arrange Direct Debit for the remaining term. There is no automatic recurring collection or Direct Debit mandate integration. The full contract amount remains separate from the WooCommerce amount charged today. Final instalments need reconciling to the agreed contract total, including leap years and partial periods.

Optional insurance is charged as **non-taxable WooCommerce fee lines**, following the requested tax treatment:

| Option | Price before any international multiplier |
|---|---|
| Vehicle insurance | 50% of the effective daily rental rate |
| Tyre insurance | £4 per day |
| Screen insurance | £4 per day |

This version doubles **all selected insurance options** when International Insurance Required is selected. For first-month payment, selected insurance is charged for `min(hire days, 365 ÷ 12)` days; otherwise it is charged for the whole hire. Both today's insurance charge and the complete contract insurance amount are displayed. Full precision is retained during calculation; currency values are displayed and charged with WooCommerce rounding. Verify the insurance terms and tax configuration before launch.

Coupons are blocked for baskets containing vehicle hire in this initial version, to avoid inconsistent deposit/remaining-balance calculations. VAT-exempt customer accounts must contact the team; this version does not quote them online. Multicurrency, WooCommerce Subscriptions, deposits plugins, custom product types and Checkout Blocks are not supported.

## Availability and reservation lifecycle

The ledger is under **WooCommerce → EVision hire**. It includes a monthly availability calendar and the existing reservation ledger. The form for adding offline bookings or maintenance blocks has been removed. Existing reservations are retained.

Availability uses inclusive collection and return dates. A car returning on Tuesday is not offered for a different collection on Tuesday, even if nominal times would permit it. This conservative rule also covers out-of-hours appointments whose exact times are unconfirmed.

Before payment, checkout takes database locks in product-ID order, checks combined fleet demand and saves reservations transactionally. Pending payments hold capacity for **30 minutes**. Processing, completed and on-hold orders retain capacity for the whole period. On-hold includes manual-payment orders awaiting your action. Cancelled, failed and fully refunded orders release capacity. Partial refunds do not release it.

A late payment whose expired hold can no longer be allocated is put on hold and flagged for manual review, with a store-admin email alert. Staff must allocate an alternative or arrange a refund. No automatic refund is attempted. Database failures stop booking/payment initiation where detected; gateway webhook and operational failure paths still require staging tests.

Existing bookings are not imported automatically. MCS is not connected. The removed offline-entry form means new MCS or telephone bookings are not reflected in this calendar automatically. Changing visible order metadata does not move a reservation; cancel/rebook to change dates in this first version. Completing an order does not release future booked dates. On-hold reservations remain until staff resolve or cancel them.

Deactivation preserves reservations. There is intentionally no automatic database deletion on uninstall. Back up the reservation table with the rest of the WordPress database.

## Bank holiday coverage

The bundled England and Wales dates were retrieved from [GOV.UK's bank holiday feed](https://www.gov.uk/bank-holidays.json) on 1 October 2026 and cover 2019–2028. The plugin attempts a daily refresh, retaining previously known dates if the request fails. Use the manual refresh action and check the last-success timestamp before launch.

For dates outside official coverage, the plugin declines online booking. For longer contracts, enter the future year's reviewed bank holiday dates in **Additional closure dates**, then add the year under **Manually reviewed future calendar years**. Continue checking for exceptional bank holidays. The public feed cannot guarantee dates that have not yet been announced. Christmas/Boxing Day falling at a weekend may have substitute official bank holidays: add the original date as an extra closure if you are also closed then.

GOV.UK information is reused under the [Open Government Licence v3.0](https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/).

## Security and testing

The plugin uses server-calculated prices, strict scalar input and option validation, staff capability checks and nonces, escaped output, parameterised database queries, isolated non-taxable insurance fees and lock-protected fleet checks. It does not collect card details, store MCS credentials or expose an authenticated external integration. Public quoting has an approximate per-IP request limit; it does not replace hosting-level abuse protection.

Tests currently include pure business rules, WooCommerce adapter behaviour with test doubles and frontend DOM interaction checks using the real PHP quotation logic. Full browser rendering could not run in the development environment. These checks are **not a live WordPress/WooCommerce/gateway integration test or an independent security audit**. Review the PHP and test visual layout and checkout on staging before processing real customer payments. Keep WordPress, WooCommerce, your theme and other plugins maintained.

### Developer checks

```sh
php tests/rules.php
php tests/woocommerce-adapter.php
php tests/product-saving.php
php tests/quote-display.php
find evision-vehicle-hire tests -name '*.php' -exec php -l {} \;
node --check evision-vehicle-hire/assets/hire.js
```

### Staging acceptance checks

- Test every bracket boundary: 6/7, 29/30, 364/365, 729/730 and 1094/1095 days.
- Check all six rates, VAT and rounding against hand calculations; check a 30-day first-month booking and a two-year booking.
- Check insurance options individually and together, international insurance, full payment and first-month payment. Confirm that insurance has no VAT and both out-of-hours charges have 20% VAT.
- Check both age/licence combinations and eligibility acknowledgement.
- Test same-day booking before/at/after midday, notice periods, outside office hours, Sundays, Saturdays, bank holidays, extra closures and daylight savings.
- Test the complete basket, checkout, order emails and order-pay flow using your gateway sandbox. Test failed/abandoned payments, delayed payment callbacks and on-hold manual payments.
- Simultaneously submit two checkouts for the last available vehicle. Only one should secure it. Confirm database lock support on the host.
- Test duplicate payment callbacks, cancellation, full and partial refunds, maintenance blocks, capacity reduction and a late payment after another customer secures the vehicle.
- Check that caching does not serve stale product nonces, quotations or availability. Exclude dynamic booking requests and normal WooCommerce basket/checkout/session pages from caching.
- Review compatibility with the actual theme, gateway, tax settings and remaining plugins. This build has not been tested against your live site's versions.

## Licence

GPL-2.0-or-later. See `LICENSE`.

## Refundable damage deposits

Under Product data → EVision hire, enter separate deposit amounts for own insurance and EVision insurance. The displayed amount changes with the vehicle-insurance checkbox. Leaving a field blank shows a concise notice pointing to the vehicle’s Insurance Information. Deposits are requested before collection, separately from the online payment, and are not added to the payable total. The selected deposit notice is recorded with the order. Return processing is stated as approximately 7–10 working days after the hire ends, subject to satisfactory vehicle condition.

The booking panel uses EVision red `#d82c27` and the website’s Proxima Nova font, with Arial as a fallback. The plugin does not bundle font files.

When vehicle insurance is selected, customers choose the total insured-driver count, including the first driver. Each driver costs 50% of the effective rental rate, doubled for international insurance. Tyre and screen insurance remain charged per vehicle. First-month proration applies to every insured driver. The count is stored with the order and all drivers must meet the displayed eligibility requirements. A small insurance note links to the full Terms and Conditions.
