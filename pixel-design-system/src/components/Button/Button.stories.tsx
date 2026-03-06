import type { Meta, StoryObj } from '@storybook/react';
import { Button } from './Button';

const meta: Meta<typeof Button> = {
  title: 'Components/Button',
  component: Button,
  argTypes: {
    variant: {
      control: 'select',
      options: ['primary', 'secondary', 'ghost', 'ghost-primary', 'destructive', 'destructive-outline'],
    },
    size: { control: 'select', options: ['sm', 'md', 'lg'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    children: 'Primary Action',
    variant: 'primary',
    size: 'md',
  },
};

export default meta;
type Story = StoryObj<typeof Button>;

export const Default: Story = {};

export const Variants: Story = {
  render: (args) => (
    <div className="flex flex-wrap gap-4">
      <Button {...args} variant="primary">Primary</Button>
      <Button {...args} variant="secondary">Secondary</Button>
      <Button {...args} variant="ghost">Ghost</Button>
      <Button {...args} variant="ghost-primary">Ghost Primary</Button>
      <Button {...args} variant="destructive">Destructive</Button>
      <Button {...args} variant="destructive-outline">Destructive Outline</Button>
    </div>
  ),
};

export const SizeMatrix: Story = {
  render: (args) => (
    <div className="flex items-center gap-4">
      <Button {...args} size="sm">Small</Button>
      <Button {...args} size="md">Default</Button>
      <Button {...args} size="lg">Large</Button>
    </div>
  ),
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid grid-cols-2 gap-4 max-w-xl">
      <Button {...args}>Default</Button>
      <Button
        {...args}
        className={args.theme === 'dark' ? '!shadow-nm-flat-d' : '!shadow-nm-flat-l'}
      >
        Tap
      </Button>
      <Button {...args} className={args.theme === 'dark' ? '!shadow-nm-raised-d' : '!shadow-nm-raised-l'}>
        Hover
      </Button>
      <Button {...args} disabled>Disabled</Button>
    </div>
  ),
};

export const WithIcon: Story = {
  args: {
    leftIcon: (
      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="square" strokeLinejoin="miter" strokeWidth="2" d="M12 4v16m8-8H4" />
      </svg>
    ),
    children: 'With Icon',
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
