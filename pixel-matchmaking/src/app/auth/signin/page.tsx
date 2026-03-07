import { redirect } from "next/navigation";
import { auth } from "@/auth";
import { SignInForm } from "@/components/SignInForm";

interface SignInPageProps {
  searchParams: Promise<{
    callbackUrl?: string;
    error?: string;
  }>;
}

export default async function SignInPage({ searchParams }: SignInPageProps) {
  const params = await searchParams;
  const session = await auth();

  // If already logged in, redirect to callback URL or home
  if (session?.user) {
    redirect(params.callbackUrl ?? "/");
  }

  return (
    <div className="flex min-h-[60vh] items-center justify-center">
      <SignInForm
        callbackUrl={params.callbackUrl ?? "/"}
        error={params.error}
      />
    </div>
  );
}
