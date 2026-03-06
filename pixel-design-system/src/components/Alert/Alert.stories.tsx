import type { Meta, StoryObj } from '@storybook/react';
import { Alert } from './Alert';

const meta: Meta<typeof Alert> = {
  title: 'Components/Alert',
  component: Alert,
  argTypes: {
    variant: { control: 'select', options: ['info', 'success', 'warning', 'error'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    variant: 'info',
    title: 'Information',
    children: 'Next match scheduled for 20H CET.',
  },
};

export default meta;
type Story = StoryObj<typeof Alert>;

export const Default: Story = {};

export const Variants: Story = {
  render: (args) => (
    <div className="grid gap-3 max-w-2xl">
      <Alert {...args} variant="info" title="Information">Next match scheduled for 20H CET.</Alert>
      <Alert {...args} variant="success" title="Success">Roster saved successfully.</Alert>
      <Alert {...args} variant="warning" title="Warning">Player eligibility expires in 48h.</Alert>
      <Alert {...args} variant="error" title="Error">Server connection lost.</Alert>
    </div>
  ),
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
