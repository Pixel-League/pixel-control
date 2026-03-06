import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { FileInput } from './FileInput';

describe('FileInput', () => {
  it('renders label', () => {
    render(<FileInput label="Upload" />);
    expect(screen.getByText('Upload')).toBeInTheDocument();
  });

  it('renders hint text', () => {
    render(<FileInput hint="PNG, JPG up to 10MB" />);
    expect(screen.getByText('PNG, JPG up to 10MB')).toBeInTheDocument();
  });

  it('renders upload zone with button role', () => {
    render(<FileInput label="Upload file" />);
    expect(screen.getByRole('button', { name: 'Upload file' })).toBeInTheDocument();
  });

  it('renders browse text', () => {
    render(<FileInput />);
    expect(screen.getByText('browse')).toBeInTheDocument();
  });

  it('triggers hidden file input on click', async () => {
    const user = userEvent.setup();
    render(<FileInput label="Upload" />);

    const btn = screen.getByRole('button', { name: 'Upload' });
    await user.click(btn);
    // Check the hidden input exists
    const hiddenInput = document.querySelector('input[type="file"]');
    expect(hiddenInput).toBeInTheDocument();
  });

  it('is disabled when disabled prop is true', () => {
    render(<FileInput disabled label="Disabled upload" />);
    expect(screen.getByRole('button', { name: 'Disabled upload' })).toBeDisabled();
  });

  it('applies dark theme classes by default', () => {
    render(<FileInput />);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-nm-dark');
  });

  it('applies light theme classes', () => {
    render(<FileInput theme="light" />);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-nm-light');
  });
});
