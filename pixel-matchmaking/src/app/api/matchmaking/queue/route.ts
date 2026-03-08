import { NextRequest, NextResponse } from 'next/server';

/**
 * In-memory matchmaking queue.
 * Stores player logins currently searching for a match.
 * Resets on server restart — placeholder until real matchmaking is implemented.
 */
const queue = new Set<string>();

export async function GET() {
  return NextResponse.json({ count: queue.size });
}

export async function POST(req: NextRequest) {
  const { login } = await req.json() as { login: string };
  if (login) queue.add(login);
  return NextResponse.json({ count: queue.size });
}

export async function DELETE(req: NextRequest) {
  const { login } = await req.json() as { login: string };
  if (login) queue.delete(login);
  return NextResponse.json({ count: queue.size });
}
