import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LinkifiedText } from '../LinkifiedText';

describe('LinkifiedText Component', () => {
  it('renders normal text without links as plain text', () => {
    const { container } = render(<LinkifiedText text="Hola mundo, este es un mensaje sin enlaces." />);
    
    expect(screen.getByText('Hola mundo, este es un mensaje sin enlaces.')).toBeInTheDocument();
    expect(container.querySelectorAll('a').length).toBe(0);
  });

  it('converts http:// and https:// URLs into <a> tags with target="_blank" and rel="noopener noreferrer"', () => {
    render(<LinkifiedText text="Visita https://t.me/enlacetv_bot/ y http://enlace.org para más información." />);

    const links = screen.getAllByRole('link');
    expect(links.length).toBe(2);

    const telegramLink = links[0];
    expect(telegramLink).toHaveAttribute('href', 'https://t.me/enlacetv_bot/');
    expect(telegramLink).toHaveAttribute('target', '_blank');
    expect(telegramLink).toHaveAttribute('rel', 'noopener noreferrer');
    expect(telegramLink.textContent).toBe('https://t.me/enlacetv_bot/');

    const enlaceLink = links[1];
    expect(enlaceLink).toHaveAttribute('href', 'http://enlace.org');
    expect(enlaceLink).toHaveAttribute('target', '_blank');
    expect(enlaceLink).toHaveAttribute('rel', 'noopener noreferrer');
    expect(enlaceLink.textContent).toBe('http://enlace.org');
  });

  it('supports multiple URLs in the same paragraph and preserves newlines', () => {
    const textWithNewlines = "Línea 1 con https://enlace.org/chat/\nLínea 2 con https://google.com\nLínea 3 final.";
    const { container } = render(<LinkifiedText text={textWithNewlines} />);

    const links = screen.getAllByRole('link');
    expect(links.length).toBe(2);
    expect(links[0]).toHaveAttribute('href', 'https://enlace.org/chat/');
    expect(links[1]).toHaveAttribute('href', 'https://google.com');

    // Container retains whitespace-pre-wrap style and class
    const wrapper = container.firstChild as HTMLElement;
    expect(wrapper).toHaveClass('whitespace-pre-wrap');
    expect(wrapper.style.whiteSpace).toBe('pre-wrap');
    expect(wrapper.textContent).toContain('Línea 1 con');
    expect(wrapper.textContent).toContain('Línea 2 con');
    expect(wrapper.textContent).toContain('Línea 3 final.');
  });

  it('excludes trailing punctuation like periods or commas from the URL', () => {
    render(<LinkifiedText text="Para consultas escribe a https://enlace.org/chat/, o visita https://enlace.org." />);

    const links = screen.getAllByRole('link');
    expect(links.length).toBe(2);
    expect(links[0]).toHaveAttribute('href', 'https://enlace.org/chat/');
    expect(links[1]).toHaveAttribute('href', 'https://enlace.org');
  });

  it('stops event propagation when link is clicked', () => {
    const handleParentClick = vi.fn();
    render(
      <div onClick={handleParentClick}>
        <LinkifiedText text="Enlace: https://enlace.org" />
      </div>
    );

    const link = screen.getByRole('link');
    fireEvent.click(link);

    expect(handleParentClick).not.toHaveBeenCalled();
  });

  it('returns null when text is empty', () => {
    const { container } = render(<LinkifiedText text="" />);
    expect(container.firstChild).toBeNull();
  });
});
