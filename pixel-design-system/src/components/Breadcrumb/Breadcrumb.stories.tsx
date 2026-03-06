import type { Meta, StoryObj } from '@storybook/react';
import { Breadcrumb } from './Breadcrumb';

const breadcrumbItems = [
  { label: 'Home', href: '#' },
  { label: 'Season 3', href: '#' },
  { label: 'Week 4' },
];

const meta: Meta<typeof Breadcrumb> = {
  title: 'Components/Breadcrumb',
  component: Breadcrumb,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    items: breadcrumbItems,
  },
};

export default meta;
type Story = StoryObj<typeof Breadcrumb>;

export const Default: Story = {};

export const LightTheme: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
