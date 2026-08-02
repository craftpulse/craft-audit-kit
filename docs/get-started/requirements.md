# Requirements

## Craft CMS

Audit Kit requires Craft CMS 5.0.0 or greater.

## PHP

Audit Kit requires PHP 8.2 or greater.

## A consuming plugin

Audit Kit is never installed on its own. It is a library-shipped Yii module, bootstrapped at runtime by a consuming plugin calling `AuditKit::register()`. Without at least one consumer making that call, the module is never constructed and the bus never exists.

## Required dependencies

`dompdf/dompdf` (`^2.0 || ^3.0`) is a hard requirement, pulled in automatically by Composer. It backs the PDF export formatter and the PDF certificate of integrity.

## Suggested dependencies

Neither of these is required, and both surfaces degrade cleanly when the package is absent.

`craftpulse/craft-auth-kit` (`^1.6`) enables the [Auth Kit bridge](../feature-tour/auth-kit-bridge.md), which relays Auth Kit authentication events onto the audit bus. With Auth Kit absent the bridge is a no-op: the listener is wired with a compile-time class string that autoloads nothing, so the target event never fires.

`aws/aws-sdk-php` (`^3.0`) enables the S3 SIEM forwarder and the S3 Object Lock anchor provider. Both sit behind an SDK-free interface seam, so the kit itself never references the SDK directly.

## Database

Audit Kit owns no tables. Chain storage is the consuming plugin's responsibility: you supply the table, the payload shape, and a persist closure, and the engine supplies the hashing and ordering guarantees. The kit's `module:audit-kit` migration track is pumped by consumers, so any schema the kit does introduce reaches every install through them.
