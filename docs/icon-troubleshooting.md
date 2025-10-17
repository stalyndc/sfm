# Icon Troubleshooting Guide

## Bootstrap Icons Not Loading - Common Issue and Solution

### Problem
Bootstrap Icons appear as empty squares or don't load at all, even though:
- CSS file is correctly linked
- Font files exist in `/assets/fonts/`
- CSP appears to be correctly configured

### Root Cause: Missing MIME Type Declarations
On shared hosting (especially Hostinger and similar providers), Apache often lacks default MIME type mappings for modern font formats. Without proper MIME types, browsers reject font files for security reasons.

### Symptoms
- Icons show as □ or empty boxes
- Browser console shows errors like:
  ```
  The resource at "https://yoursite.com/assets/fonts/bootstrap-icons.woff2" was blocked because MIME type ("application/octet-stream") is not a supported font MIME type.
  ```
- Network tab shows font files loading with `Content-Type: application/octet-stream` instead of `font/woff2`

### Quick Fix
Add these MIME type declarations to your `.htaccess` file:

```apache
# Font MIME types for Bootstrap Icons and web fonts
AddType font/woff2 .woff2
AddType font/woff .woff
AddType font/ttf .ttf
AddType font/eot .eot
AddType font/otf .otf
```

Place this section after the `RewriteEngine On` directive but before any `<IfModule>` blocks.

### Complete .htaccess Font Section Example

```apache
# ------------------------------------------------------------------
# Font MIME types (required for Bootstrap Icons on shared hosting)
# ------------------------------------------------------------------
AddType font/woff2 .woff2
AddType font/woff .woff
AddType font/ttf .ttf
AddType font/eot .eot
AddType font/otf .otf

# ------------------------------------------------------------------
# Compression for fonts (optional but recommended)
# ------------------------------------------------------------------
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE font/woff font/woff2 font/ttf font/eot font/otf
</IfModule>
```

### Verification Steps
1. **Check Network Tab**: Look for font files and verify they have correct `Content-Type` headers
2. **Test MIME Types**: Create a simple PHP file to test:
   ```php
   <?php
   header('Content-Type: font/woff2');
   readfile('/path/to/bootstrap-icons.woff2');
   ?>
   ```
3. **Backup First**: Always backup `.htaccess` before making changes

### Related Common Issues

#### CSP Configuration
If you're still having issues after fixing MIME types, check your CSP:
```apache
# In your CSP header, ensure font-src includes:
font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://unpkg.com data:;
```

#### File Permissions
Ensure font files have correct permissions:
```
chmod 644 assets/fonts/*.woff*
chmod 755 assets/fonts/
```

#### Server Configuration
Some shared hosting providers may require:
- `.htaccess` to be enabled
- `AllowOverride All` in Apache config
- Specific MIME type modules loaded

### Prevention
1. **Document this fix** in your project setup checklist
2. **Include in deployment scripts** to ensure it's never forgotten
3. **Test on staging** before deploying to production
4. **Monitor browser console** for font-related errors after updates

### Alternative Solutions (If .htaccess Doesn't Work)
If `.htaccess` changes aren't effective:
1. **Contact hosting support** to add MIME types globally
2. **Use CDN-based Bootstrap Icons** instead of local files
3. **Encode fonts as base64** in CSS (not recommended for performance)

### Files Most Likely Affected
- `.htaccess` (root directory)
- `/assets/fonts/bootstrap-icons.woff2`
- `/assets/fonts/bootstrap-icons.woff`
- `/assets/css/bootstrap-icons.min.css`

### Quick Commands for Debugging
```bash
# Check current MIME type of font file
curl -I https://yoursite.com/assets/fonts/bootstrap-icons.woff2

# Verify file exists and has correct permissions
ls -la assets/fonts/

# Test if .htaccess is being processed
echo "AddType text/plain .test" >> .htaccess
# Then create test.test and see if it serves as text/plain
```

---

**Note for Future AI Agents**: When troubleshooting icon issues, always check MIME types first before investigating CSP, file paths, or JavaScript errors. This is the #1 cause of Bootstrap Icons failing to load on shared hosting.
