import { StartNode } from './StartNode';
import { MessageNode } from './MessageNode';
import { ImageNode } from './ImageNode';
import { FileNode } from './FileNode';
import { QuestionNode } from './QuestionNode';
import { ConditionNode } from './ConditionNode';
import { MenuNode } from './MenuNode';
import { HttpRequestNode } from './HttpRequestNode';
import { GotoNode } from './GotoNode';
import { SetVariableNode } from './SetVariableNode';
import { TransferNode } from './TransferNode';
import { EndNode } from './EndNode';

export {
  StartNode,
  MessageNode,
  ImageNode,
  FileNode,
  QuestionNode,
  ConditionNode,
  MenuNode,
  HttpRequestNode,
  GotoNode,
  SetVariableNode,
  TransferNode,
  EndNode,
};

export const flowNodeTypes = {
  start: StartNode,
  message: MessageNode,
  image: ImageNode,
  file: FileNode,
  question: QuestionNode,
  condition: ConditionNode,
  menu: MenuNode,
  http_request: HttpRequestNode,
  goto: GotoNode,
  set_variable: SetVariableNode,
  transfer_to_agent: TransferNode,
  end: EndNode,
};
