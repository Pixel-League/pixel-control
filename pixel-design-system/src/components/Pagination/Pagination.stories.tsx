import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Pagination } from './Pagination';

const meta: Meta<typeof Pagination> = {
  title: 'Components/Pagination',
  component: Pagination,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    totalPages: 12,
    currentPage: 1,
  },
};

export default meta;
type Story = StoryObj<typeof Pagination>;

export const Interactive: Story = {
  render: (args) => {
    const [page, setPage] = useState(args.currentPage);
    return <Pagination {...args} currentPage={page} onPageChange={setPage} />;
  },
};

export const LightTheme: Story = {
  render: (args) => {
    const [page, setPage] = useState(args.currentPage);
    return <Pagination {...args} theme="light" currentPage={page} onPageChange={setPage} />;
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
