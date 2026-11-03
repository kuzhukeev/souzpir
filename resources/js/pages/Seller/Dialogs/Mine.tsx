import Index from './Index';

export default function Mine(props: Parameters<typeof Index>[0]) {
  return <Index {...props} minePage />;
}
