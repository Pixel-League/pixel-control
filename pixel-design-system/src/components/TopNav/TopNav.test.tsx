import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TopNav } from './TopNav';

describe('TopNav', () => {
  it('renders brand content', () => {
    render(<TopNav brand={<span>PIXEL SERIES</span>} />);
    expect(screen.getByText('PIXEL SERIES')).toBeInTheDocument();
  });

  it('renders navigation links', () => {
    render(
      <TopNav
        brand={<span>Logo</span>}
        links={[
          { label: 'Planning', active: true },
          { label: 'Ranking' },
        ]}
      />,
    );
    expect(screen.getByText('Planning')).toBeInTheDocument();
    expect(screen.getByText('Ranking')).toBeInTheDocument();
  });

  it('marks active link with aria-current', () => {
    render(
      <TopNav
        brand={<span>Logo</span>}
        links={[{ label: 'Home', active: true }]}
      />,
    );
    expect(screen.getByText('Home')).toHaveAttribute('aria-current', 'page');
  });

  it('renders actions slot', () => {
    render(
      <TopNav
        brand={<span>Logo</span>}
        actions={<button>Register</button>}
      />,
    );
    expect(screen.getByText('Register')).toBeInTheDocument();
  });

  it('has navigation role', () => {
    render(<TopNav brand={<span>Logo</span>} />);
    expect(screen.getByRole('navigation')).toBeInTheDocument();
  });

  it('applies dark theme classes by default', () => {
    render(<TopNav brand={<span>Logo</span>} />);
    expect(screen.getByRole('navigation').className).toContain('bg-nm-dark');
  });

  it('applies light theme classes', () => {
    render(<TopNav brand={<span>Logo</span>} theme="light" />);
    expect(screen.getByRole('navigation').className).toContain('bg-nm-light');
  });

  it('applies active link inset shadow in dark theme', () => {
    render(
      <TopNav
        brand={<span>Logo</span>}
        links={[{ label: 'Active', active: true }]}
      />,
    );
    expect(screen.getByText('Active').className).toContain('shadow-nm-inset-d');
  });
});
