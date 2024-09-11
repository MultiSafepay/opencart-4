# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

***

## 1.4.0
Release date: September, 11th 2024

### Added
+ PLGOPS4-68: Add support to OpenCart versions higher than 4.0.1.x
+ DAVAMS-737: Add BNPL_MF

### Changed
+ DAVAMS-744: Rebranding payment method: in3 B2C

### Fixed
+ DAVAMS-754: Fix 'Template ID' within the 'Payment Component'

***

## 1.3.0
Release date: February, 15th 2024

### Added
+ DAVAMS-682: Add support for MB WAY payment method
+ DAVAMS-683: Add support for Multibanco payment method
+ DAVAMS-684: Add support for Template ID in the Payment Component

### Changed
+ DAVAMS-664: Rebrand in3 payment method name and description and remove birthday checkout field

### Removed
+ DAVAMS-711: Remove Santander Betaal per Maand

***

## 1.2.0
Release date: September, 26th 2023

### Fixed
+ DAVAMS-665: Refactoring for better error handling

### Changed
+ DAVAMS-642: Improvements over the Payment Component

### Added
+ DAVAMS-663: Add Zinia payment method

***

## 1.1.1
Release date: June, 22nd 2023

### Fixed
+ PLGOPS4-60: Fix error 'Invalid or duplicate merchant_item_id', when processing a Klarna order, and the shopping cart contains two items with the same product but different options.

### Changed
+ DAVAMS-605: Rename "Credit Card" payment method as "Card payment".

***

## 1.1.0
Release date: Feb, 23rd 2023

### Added
+ DAVAMS-578: Add Pay After Delivery Installments payment method

### Removed
+ DAVAMS-572: Remove Google Analytics tracking ID within the order request

***

## 1.0.0
Release date: January, 11th 2023

### Added
+ Payment Methods
  - Alipay
  - Alipay+ ™ Partner
  - Amazon Pay
  - American Express
  - Apple Pay
  - Bancontact
  - Bank transfer
  - Belfius
  - CBC
  - Card payment
  - Dotpay
  - E-Invoicing
  - EPS
  - Giropay
  - Google Pay
  - iDEAL
  - iDEAL QR
  - in3
  - KBC
  - Klarna - Pay in 30 days
  - Maestro
  - Mastercard
  - MultiSafepay
  - MyBank - Bonifico Immediato
  - Pay After Delivery
  - PayPal
  - Paysafecard
  - Request to Pay powered by Deutsche Bank
  - Riverty 
  - SEPA Direct Debit
  - Santander Consumer Finance | Pay per month
  - Sofort
  - Trustly
  - Visa

+ Gift Cards
  - Baby Cadeaubon
  - Beauty & Wellness
  - Boekenbon
  - Fashioncheque
  - Fashiongiftcard
  - Fietsenbon
  - Gezondheidsbon
  - GivaCard
  - Good4fun Giftcard
  - Good Card
  - Nationale Tuinbon
  - Parfum Cadeaukaart
  - Podium
  - Sport & Fit
  - VVV Cadeaukaart
  - Webshop Giftcard
  - Wellness gift card
  - Wijncadeau
  - Winkelcheque
  - YourGift

+ Features:
  - Process payments with all the MultiSafepay payment methods and gift cards. 
  - Set the MultiSafepay transaction to shipped from the backoffice
  - Set the MultiSafepay transaction to invoiced from the backoffice
  - Full refunds from the backoffice
  - Order status 
