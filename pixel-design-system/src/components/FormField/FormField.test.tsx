import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FormField } from './FormField';

describe('FormField', () => {
  it('renders label and helper text', () => {
    render(
      <FormField label="Email" helperText="Required" htmlFor="email">
        <input id="email" />
      </FormField>,
    );

    expect(screen.getByText('Email')).toBeInTheDocument();
    expect(screen.getByText('Required')).toBeInTheDocument();
  });

  it('renders required marker when required', () => {
    render(
      <FormField label="Team" required htmlFor="team">
        <input id="team" />
      </FormField>,
    );

    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('passes htmlFor to label', () => {
    render(
      <FormField label="Alias" htmlFor="alias">
        <input id="alias" />
      </FormField>,
    );

    expect(screen.getByText('Alias')).toHaveAttribute('for', 'alias');
  });
});
