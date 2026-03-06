import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Table, type TableColumn } from './Table';

interface TestRow {
  id: number;
  name: string;
  score: number;
}

const columns: TableColumn<TestRow>[] = [
  { key: 'id', header: '#' },
  { key: 'name', header: 'Name' },
  { key: 'score', header: 'Score' },
];

const data: TestRow[] = [
  { id: 1, name: 'OWNED', score: 36 },
  { id: 2, name: 'CRYSTAL', score: 30 },
];

describe('Table', () => {
  it('renders column headers', () => {
    render(<Table<TestRow> columns={columns} data={data} rowKey="id" />);
    expect(screen.getByText('#')).toBeInTheDocument();
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('Score')).toBeInTheDocument();
  });

  it('renders row data', () => {
    render(<Table<TestRow> columns={columns} data={data} rowKey="id" />);
    expect(screen.getByText('OWNED')).toBeInTheDocument();
    expect(screen.getByText('36')).toBeInTheDocument();
  });

  it('calls onRowClick when row is clicked', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();
    render(
      <Table<TestRow> columns={columns} data={data} rowKey="id" onRowClick={onRowClick} />,
    );

    await user.click(screen.getByText('OWNED'));
    expect(onRowClick).toHaveBeenCalledWith(data[0], 0);
  });

  it('uses custom render function', () => {
    const cols: TableColumn<TestRow>[] = [
      { key: 'name', header: 'Team' },
      {
        key: 'score',
        header: 'Pts',
        render: (v) => <strong data-testid="bold">{String(v)}</strong>,
      },
    ];

    render(<Table<TestRow> columns={cols} data={data} rowKey="id" />);
    expect(screen.getAllByTestId('bold')).toHaveLength(2);
  });

  it('applies dark theme classes by default', () => {
    const { container } = render(
      <Table<TestRow> columns={columns} data={data} rowKey="id" />,
    );
    expect(container.querySelector('.bg-px-primary')).toBeInTheDocument();
  });

  it('applies light theme classes', () => {
    const { container } = render(
      <Table<TestRow> columns={columns} data={data} rowKey="id" theme="light" />,
    );
    expect(container.querySelector('.bg-px-offblack')).toBeInTheDocument();
  });

  it('supports function rowKey', () => {
    render(
      <Table<TestRow>
        columns={columns}
        data={data}
        rowKey={(row) => `row-${row.id}`}
      />,
    );
    expect(screen.getByText('OWNED')).toBeInTheDocument();
  });
});
