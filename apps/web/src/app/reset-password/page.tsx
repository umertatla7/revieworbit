import { PasswordResetForm } from "@/components/password-reset-form";

export default async function ResetPasswordPage({ searchParams }: { searchParams: Promise<{ token?: string; email?: string }> }) {
  const values = await searchParams;
  return <PasswordResetForm token={values.token ?? ""} email={values.email ?? ""} />;
}
