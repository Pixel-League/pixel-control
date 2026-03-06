import type { Meta, StoryObj } from '@storybook/react';
import { FormField } from './FormField';

const meta: Meta<typeof FormField> = {
  title: 'Components/FormField',
  component: FormField,
  argTypes: {
    state: { control: 'select', options: ['default', 'error', 'success'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    label: 'Match title',
    helperText: 'Optional helper message',
    state: 'default',
    children: (
      <input
        className="w-full px-4 py-3 bg-nm-dark shadow-nm-inset-d border border-white/[0.08] text-px-white"
        placeholder="OWNED vs CRYSTAL"
      />
    ),
  },
};

export default meta;
type Story = StoryObj<typeof FormField>;

export const Default: Story = {};

export const Error: Story = {
  args: {
    state: 'error',
    helperText: 'Title is required',
  },
};

export const Success: Story = {
  args: {
    state: 'success',
    helperText: 'Looks good',
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    children: (
      <input
        className="w-full px-4 py-3 bg-nm-light shadow-nm-inset-l border border-black/[0.08] text-px-offblack"
        placeholder="OWNED vs CRYSTAL"
      />
    ),
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
