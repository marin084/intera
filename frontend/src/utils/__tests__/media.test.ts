import { describe, it, expect } from 'vitest';
import { resolveMediaUrl, isSafeMediaUrl } from '../media';

describe('media utils security and resolution', () => {
  describe('isSafeMediaUrl', () => {
    it('returns false for null, undefined, or empty values', () => {
      expect(isSafeMediaUrl(null)).toBe(false);
      expect(isSafeMediaUrl(undefined)).toBe(false);
      expect(isSafeMediaUrl('')).toBe(false);
      expect(isSafeMediaUrl('   ')).toBe(false);
    });

    it('blocks dangerous protocols like javascript: and data:text/html', () => {
      expect(isSafeMediaUrl('javascript:alert(1)')).toBe(false);
      expect(isSafeMediaUrl('JAVASCRIPT:alert(1)')).toBe(false);
      expect(isSafeMediaUrl('javascript:/*--></title></style></textarea></script>*/')).toBe(false);
      expect(isSafeMediaUrl('data:text/html,<script>alert(1)</script>')).toBe(false);
      expect(isSafeMediaUrl('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==')).toBe(false);
      expect(isSafeMediaUrl('vbscript:msgbox(1)')).toBe(false);
      expect(isSafeMediaUrl('file:///etc/passwd')).toBe(false);
      expect(isSafeMediaUrl('//malicious.com/exploit.js')).toBe(false);
    });

    it('allows safe http, https, blob and relative URLs', () => {
      expect(isSafeMediaUrl('https://example.com/image.png')).toBe(true);
      expect(isSafeMediaUrl('http://localhost:8000/storage/attachment.pdf')).toBe(true);
      expect(isSafeMediaUrl('/storage/attachments/2026/04/file.png')).toBe(true);
      expect(isSafeMediaUrl('blob:http://localhost:3000/some-uuid')).toBe(true);
      expect(isSafeMediaUrl('data:image/png;base64,iVBORw0KGgo=')).toBe(true);
      expect(isSafeMediaUrl('data:image/jpeg;base64,/9j/4AAQSkZJRg==')).toBe(true);
    });
  });

  describe('resolveMediaUrl', () => {
    it('returns empty string for null, undefined, or empty values', () => {
      expect(resolveMediaUrl(null)).toBe('');
      expect(resolveMediaUrl(undefined)).toBe('');
      expect(resolveMediaUrl('')).toBe('');
      expect(resolveMediaUrl('   ')).toBe('');
    });

    it('rejects unsafe protocols by returning empty string', () => {
      expect(resolveMediaUrl('javascript:alert(document.cookie)')).toBe('');
      expect(resolveMediaUrl('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==')).toBe('');
      expect(resolveMediaUrl('//evil.com/xss.js')).toBe('');
    });

    it('normalizes localhost:8000 storage URLs to relative /storage/...', () => {
      const raw = 'http://localhost:8000/storage/flows/abc/test.png';
      expect(resolveMediaUrl(raw)).toBe('/storage/flows/abc/test.png');
    });

    it('normalizes backend:8000 storage URLs to relative /storage/...', () => {
      const raw = 'http://backend:8000/storage/flows/abc/test.png';
      expect(resolveMediaUrl(raw)).toBe('/storage/flows/abc/test.png');
    });

    it('preserves relative /storage/... URLs', () => {
      const raw = '/storage/flows/123/file.webp';
      expect(resolveMediaUrl(raw)).toBe('/storage/flows/123/file.webp');
    });

    it('preserves blob and safe image data URIs', () => {
      expect(resolveMediaUrl('blob:http://localhost:3000/some-uuid')).toBe('blob:http://localhost:3000/some-uuid');
      expect(resolveMediaUrl('data:image/png;base64,iVBORw...')).toBe('data:image/png;base64,iVBORw...');
    });

    it('preserves external CDN URLs', () => {
      const raw = 'https://images.unsplash.com/photo-12345';
      expect(resolveMediaUrl(raw)).toBe('https://images.unsplash.com/photo-12345');
    });
  });
});
